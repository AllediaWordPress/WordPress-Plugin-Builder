<?php
namespace PressShack\Builder;

use DirectoryIterator;
use \Robo\Common\ExecOneCommand;

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
class Robo_Task extends \Robo\Tasks
{
    protected $source_path = 'src';

    protected $packages_path = 'packages';

    protected $plugin_name = 'unnamed';

    protected $version_constant = 'VERSION';

    /**
     * Get the current version of the plugin
     */
    protected function getVersion()
    {
        $file = file_get_contents($this->source_path . '/' .  $this->plugin_name . '.php');

        preg_match('/Version:\s*([0-9\.a-z\-]*)/i', $file, $matches);

        return $matches[1];
    }

    /**
     * Returns the package name
     *
     * @return string
     */
    protected function getPackageName()
    {
        return $this->plugin_name . '-' . $this->getVersion() . '.zip';
    }

    /**
     * Build the ZIP package
     *
     * @param string $destination Destination for the package. The ZIP file will be moved to that path.
     */
    public function build($destination = null)
    {
        $this->print_header();

        $destination = getenv('PS_GLOBAL_PACKAGES_PATH');

        // Create packages folder if not exists
        if (! file_exists($this->packages_path)) {
            mkdir($this->packages_path);
        }

        // Prepare the variables
        $version        = $this->getVersion();
        $filename       = $this->getPackageName();
        $filePath       = $this->packages_path . '/'. $filename;
        $tmpPath        = tempnam(sys_get_temp_dir(), 'dir');
        $pack           = $this->taskPack($filePath);
        $folderPath     = $tmpPath .'/' . $this->plugin_name;

        $this->say('Building package for version ' . $version);

        // Remove existent package
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Cleanup on the tmp folder
        if (file_exists($tmpPath)) {
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            } else {
                $this->_deleteDir($tmpPath);
            }
        }
        mkdir($tmpPath);

        // Create the main folder inside the tmp folder
        mkdir($folderPath);

        // Copy the src folder to the folder inside the tmp folder
        $this->taskCopyDir([$this->source_path => $folderPath])
            ->exclude([
                '.',
                '..',
                'build',
                'tests',
                '.git',
                '.gitignore',
                'README',
                'README.md',
                '.DS_Store',
                '.babelrc',
                'package.json',
                'composer.json',
                'composer.lock',
            ])
            ->run();

        // Add to the package
        $srcContent = scandir($tmpPath);
        foreach ($srcContent as $content) {
            $ignore = [
                '.',
                '..',
            ];

            if (! in_array($content, $ignore)) {
                $path = $tmpPath . '/' . $content;

                if (is_file($path)) {
                    $pack->addFile($content, $path);
                } else {
                    $pack->addDir($content, $path);
                }
            }
        }

        $return = $pack->run();

        // Removes tmp dir
        // $this->_deleteDir($tmpPath);

        // Should we move to any specific destination?
        if (!empty($destination)) {
            if (!realpath($destination)) {
                throw new RuntimeException('Invalid destination path');
            }

            $destFile = realpath($destination) . '/' . $filename;

            $this->say('Moving the new package to ' . $destFile);

            rename($this->packages_path . '/' . $filename, $destFile);
        }

        $this->say("Package built successfully");

        return $return;
    }

    /**
     * Build and move the package to an S4 bucket. After moving, display and
     * copy the shared link for the file.
     *
     * Tested on linux only.
     *
     * Requirements:
     *
     *    - s3cmd <http://s3tools.org>
     *    - xclip
     *
     * Configuring:
     *
     *    - Run: s3cmd --configure
     *    - Set the environment variables:
     *        - PS_S3_BUCKET
     *
     */
    public function buildS3() {
        $this->print_header();

        $s3Bucket = getenv('PS_S3_BUCKET');
        $filename = $this->getPackageName();
        $filePath = $this->packages_path . '/'. $filename;

        $this->build();

        // Should we move to any specific destination?
        $destination = getenv('PS_GLOBAL_PACKAGES_PATH');
        if (!empty($destination)) {
            if (!realpath($destination)) {
                throw new RuntimeException('Invalid destination path');
            }

            $filePath = realpath($destination) . '/' . $filename;
        }

        // Create new prefix
        $prefix = md5(microtime());

        // Upload the new package to s3
        $s3Path = sprintf(
            's3://%s/%s/%s',
            $s3Bucket,
            $prefix,
            $filename
        );
        $cmd    = sprintf(
            's3cmd put --acl-public --reduced-redundancy %s %s',
            $filePath,
            $s3Path
        );
        $this->_exec($cmd);

        // Copy the public link to the clipboard
        if (stripos(PHP_OS, 'darwin') === 0) {
            $pbcode = 'pbcopy';
        } elseif (stripos(PHP_OS, 'linux') === 0) {
            $pbcode = 'xclip -selection "clipboard"';
        }

        $this->_exec('s3cmd info ' . $s3Path . ' | grep "URL:" | awk \'{ print $2 }\' | ' . $pbcode);
    }

    /**
     * Return a list of PO files from the languages dir
     *
     * @return string
     */
    protected function getPoFiles()
    {
        return glob(SOURCE_PATH . 'languages' . '/*.po');
    }

    /**
     * Compile language MO files from PO files.
     *
     * @param string $poFile
     * @return Result
     */
    protected function compileMOFromPO($poFile)
    {
        $moFile = str_replace('.po', '.mo', $poFile);

        return $this->taskExec('msgfmt')
                ->arg('-o' . $moFile)
                ->arg($poFile)
                ->run();
    }

    /**
     * Compile all PO language files
     */
    public function langCompile()
    {
        $this->print_header();

        $return = null;
        $files  = $this->getPoFiles();

        foreach ($files as $file) {
            $return = $this->compileMOFromPO($file);

            $this->say('Language file compiled');
        }

        return $return;
    }

    /**
     * Watch language files and compile the changed ones to MO files.
     */
    public function langWatch()
    {
        $this->print_header();

        $return = null;
        $task   = $this->taskWatch();
        $files  = $this->getPoFiles();

        foreach ($files as $file) {
            $task->monitor($file, function() use ($file) {
                $return = $this->compileMOFromPO($file);

                $this->say('Language file compiled');
            });
        }

        $task->run();

        return $return;
    }

    /**
     * Sync staging files with src files
     */
    public function syncWp()
    {
        $this->print_header();

        $wpPath      = getenv('PS_WP_PATH');
        $stagingPath = $wpPath  . '/wp-content/plugins/' . $this->plugin_name;

        if (empty($wpPath)) {
            throw new RuntimeException('Invalid WordPress path. Please, set the environment variable: PS_WP_PATH');
        }

        if (!file_exists($wpPath . '/wp-config.php')) {
            throw new RuntimeException('WordPress not found on: ' . $wpPath . '. Check the PS_WP_PATH environment variable');
        }

        if (is_dir($stagingPath)) {
            $this->_cleanDir($stagingPath);
        } else {
            mkdir($stagingPath);
        }

        // Copy the src folder
        $this->_copyDir($this->source_path, $stagingPath);

        return true;
    }

    /**
     * Sync src files with staging files
     */
    public function syncSrc()
    {
        $this->print_header();

        $wpPath      = getenv('PS_WP_PATH');
        $stagingPath = $wpPath  . '/wp-content/plugins/' . $this->plugin_name;

        if (empty($wpPath)) {
            throw new RuntimeException('Invalid WordPress path. Please, set the environment variable: PS_WP_PATH');
        }

        if (!file_exists($wpPath . '/wp-config.php')) {
            throw new RuntimeException('WordPress not found on: ' . $wpPath . '. Check the PS_WP_PATH environment variable');
        }

        // Cleanup the src folder
        $this->_cleanDir($this->source_path);

        // Copy to the src folder
        $this->_copyDir($stagingPath, $this->source_path);

        return true;
    }

    public function gitCleanup()
    {
        $this->print_header();

        shell_exec('git clean -xdf ' . $this->source_path);
    }

    /**
     * Display the current version of the plugin
     */
    public function version() {
        $this->print_header();
    }

    /**
     * Set a new version to the plugin
     */
    public function versionSet( $newVersion = null ) {
        $this->print_header();

        // If empty, ask for a new version
        if ( empty( $newVersion ) ) {
            $newVersion = $this->io()->ask('New version: ');
        }

        // Make sure we don't have an empty version
        if ( empty( $newVersion ) ) {
            $newVersion = $this->getVersion();
        }

        $this->say( 'Original version: ' . $this->getVersion() );

        $this->updateVersionMainPluginFile( $newVersion );
        $this->udpateVersionTxtFile( $newVersion );
        $this->udpateIncludeFile( $newVersion );

        $this->say( 'Current version: ' . $this->getVersion() );
    }

    /**
     * Updates the version in the main plugin file
     */
    protected function updateVersionMainPluginFile( $newVersion ) {
        $file = $this->source_path . '/' .  $this->plugin_name . '.php';
        $content = file_get_contents( $file );

        $content = preg_replace('/( *)\*( *)Version:( *)([0-9\-a-z\.]+)/', '$1*$2Version:$3____NEW_VERSION____' , $content);
        $content = str_replace( '____NEW_VERSION____', $newVersion, $content);

        if ( file_put_contents( $file, $content ) ) {
            $this->say( 'Updated file: ' . $this->plugin_name . '.php' );
        }
    }

    /**
     * Returns true if the given version is a stable release.
     *
     * @return bool
     */
    protected function is_stable_version( $version ) {
        return ! preg_match( '/[a-z]/', $version );
    }

    /**
     * Updates the version in the plugin's txt file, if it is a
     * stable version.
     */
    protected function udpateVersionTxtFile( $newVersion ) {
        if ( ! $this->is_stable_version( $newVersion ) ) {
            return;
        }

        $file = $this->source_path . '/readme.txt';
        $content = file_get_contents( $file );

        $content = preg_replace('/Stable tag:( *)([0-9\-a-z\.]+)/', 'Stable tag:$1____NEW_VERSION____' , $content);
        $content = str_replace( '____NEW_VERSION____', $newVersion, $content);

        if ( file_put_contents( $file, $content ) ) {
            $this->say( 'Updated file: readme.txt' );
        }
    }

    /**
     * Updates the version in the plugin's txt file, if it is a
     * stable version.
     */
    protected function udpateIncludeFile( $newVersion ) {
        $file = $this->source_path . '/includes.php';
        $content = file_get_contents( $file );

        $content = preg_replace(
            '/(\s*define\(\ *[\'"]' . $this->version_constant . '[\'"],\ [\'"])[0-9\-\.a-z]+([\'"])/',
            '$1____NEW_VERSION____$2',
            $content
        );
        $content = str_replace( '____NEW_VERSION____', $newVersion, $content);

        if ( file_put_contents( $file, $content ) ) {
            $this->say( 'Updated file: includes.php' );
        }
    }

    /**
     * Print the header.
     */
    protected function print_header() {

        $title = "PressShack Builder Script: " . $this->plugin_name . "\n";
        $title .= str_repeat( '-', strlen( $title ) ) . "\n";
        $title .= "Plugin version: {$this->getVersion()}\n";

        $this->io()->title($title);
    }

    /**
     * Deploy to Git and SVN
     */
    public function release( $new_version = null ) {
        $this->print_header();

        /**

            TODO:
            - Check current git branch. Should be master. Ask for confirmation if not.
            - Check if we have a clean working copy. Abort if not.
            - Do we have a github tag for the new version?
            - Automatically release to Github, sending the package.

         */


        $new_version = $this->askDefault('Version to release: ', $this->getVersion());

        if ( ! $this->confirm('Do you really want to release the version ' . $new_version) ) {
            $this->say('Aborting! Thanks');
            return;
        }

        // Set the new version
        $this->versionSet( $new_version );

        $svn_path = getenv('PS_WP_SVN_PATH') . '/' . $this->plugin_name;

        if ( ! realpath( $svn_path ) ) {
            $this->say('SVN repo Path invalid: ' . $svn_path);

            return ;
        }

        $svn_trunk = $svn_path . '/trunk';

        $this->say('SVN repo: ' . $svn_path);
        $this->io->writeln('');

        $this->yell('Preparing to release...', 40, 'blue');

        $this->io->writeln('');

        // Resetting the SVN repo
        $this->say('Reseting SVN repository to the lastest version...');
        $this->_exec("svn status {$svn_path} --no-ignore");

        // Remove added files before update
        $result = $this->taskExec('svn st ' . $svn_path)
            ->printOutput(false)
            ->run();

        $result = $result->getMessage();
        preg_match_all('/^\?\s*([a-z0-9\-_\.\/]*)/im', $result, $matches);

        if (isset($matches[1]) && !empty($matches[1])) {
            foreach ($matches[1] as $file) {
                $file = trim($file);

                $this->_exec('rm ' . $file);
            }
        }

        $this->_exec("svn update {$svn_path} --force");


        // Cleanup the public folder in the SVN repo
        $this->say('Cleaning up the svn/trunk directory...');

        if ( is_dir( $svn_trunk ) ) {
            $this->rrmdir( $svn_trunk );
        }

        mkdir( $svn_trunk );

        // Copy the new code to the trunk folder
        $this->say('Copying new code to trunk...');
        $this->copy_dir_content( $this->source_path, $svn_trunk);

        // Check if we have new files
        $result = $this->taskExec('svn st ' . $svn_path)
            ->printOutput(false)
            ->run();

        $result = $result->getMessage();

        preg_match_all('/^\?\s*([a-z0-9\-_\.\/]*)/im', $result, $matches);

        if (isset($matches[1]) && !empty($matches[1])) {
            foreach ($matches[1] as $file) {
                $file = trim($file);

                $this->_exec('svn add ' . $file);
            }
        }

        // Check if we have deleted files
        preg_match_all('/^\!\s*([a-z0-9\-_\.\/]*)/im', $result, $matches);

        if (isset($matches[1]) && !empty($matches[1])) {
            foreach ($matches[1] as $file) {
                $file = trim($file);

                $this->_exec('svn rm ' . $file);
            }
        }

        // Check if we have changes to commit

        // Remove added files before update
        $result = $this->taskExec('svn st ' . $svn_path)
            ->printOutput(false)
            ->run();

        $result = trim($result->getMessage());

        if (empty($result)) {
            $this->yell('Nothing to commit. Aborting release...', 40, 'red');

            return ;
        }

        $commit_message = 'Releasing ' . $new_version;
        $commit_message = $this->askDefault( 'Commit message: ', $commit_message );

        // Commit
        $this->_exec("svn ci {$svn_path} -m \"{$commit_message}\"");

        // Tagging the new version
        $this->_exec("svn cp {$svn_path}/trunk {$svn_path}/tags/{$new_version}");
        $this->_exec("svn ci {$svn_path} -m \"Tagging {$new_version}\"");

        $this->writeln('');
        $this->say("Version {$new_version} released successfully");
    }

    /**
     * Recursively removes a folder along with all its files and directories
     *
     * @param String $path
     */
    protected function rrmdir($path) {
        // Open the source directory to read in files
        $i = new DirectoryIterator($path);
        foreach($i as $f) {
            if($f->isFile()) {
                unlink($f->getRealPath());
            } else if(!$f->isDot() && $f->isDir()) {
                $this->rrmdir($f->getRealPath());
            }
        }

        rmdir($path);
    }

    /**
     * Copy content of directory to another.
     *
     * @param  [type] $source [description]
     * @param  [type] $dest   [description]
     * @return [type]         [description]
     */
    protected function copy_dir_content($source, $dest) {
        foreach (
            $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST) as $item
        ) {
            if ($item->isDir()) {
                mkdir($dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            } else {
                copy($item, $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            }
        }
    }
}
