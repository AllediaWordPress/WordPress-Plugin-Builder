<?php
namespace PressShack\Builder;

use Robo\Exception\TaskExitException;

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
class Base extends \Robo\Tasks
{
    protected $source_path = 'src';

    protected $packages_path = 'packages';

    protected $plugin_name = 'unnamed';

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
     * Build the ZIP package
     *
     * @param string $destination Destination for the package. The ZIP file will be moved to that path.
     */
    public function build($destination = null)
    {
        $this->say('Building the package');

        // Update composer dependencies
        $this->_exec('composer update --no-dev -d ' . $this->source_path );

        // Create packages folder if not exists
        if (! file_exists($this->packages_path)) {
            mkdir($this->packages_path);
        }

        // Prepare the variables
        $filename = $this->plugin_name . '.zip';
        $packPath = $this->packages_path . '/'. $filename;
        $tmpPath  = tempnam(sys_get_temp_dir(), 'dir');
        $pack     = $this->taskPack($packPath);

        // Remove existent package
        if (file_exists($packPath)) {
            unlink($packPath);
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

        // Copy the src folder
        $this->_copyDir($this->source_path, $tmpPath);

        // Add to the package
        $srcContent = scandir($tmpPath);
        foreach ($srcContent as $content) {
            $ignore = array(
                '.',
                '..',
                'build',
                'tests',
                '.git',
                '.gitignore',
                'README',
                '.DS_Store',
                '.babelrc',
                'package.json',
                'composer.json',
                'composer.lock',
            );

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
        $this->_deleteDir($tmpPath);

        // Should we move to any specific destination?
        if (!is_null($destination)) {
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
     * Build and move the package to a global path, set by
     * PS_GLOBAL_PACKAGES_PATH
     */
    public function buildMove() {
        $new_path = getenv('PS_GLOBAL_PACKAGES_PATH');

        if (! empty($new_path)) {
            $this->build($new_path);
        }
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
        $s3Bucket = getenv('PS_S3_BUCKET');
        $filename = $this->plugin_name . '.zip';
        $packPath = $this->packages_path . '/'. $filename;

        $this->build();

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
            $packPath,
            $s3Path
        );
        $this->_exec($cmd);

        // Copy the public link to the clipboard
        $this->_exec('s3cmd info ' . $s3Path . ' | grep "URL:" | awk \'{ print $2 }\' | xclip -selection "clipboard"');
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
        shell_exec('git clean -xdf ' . $this->source_path);
    }
}
