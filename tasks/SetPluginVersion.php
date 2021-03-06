<?php
require_once 'phing/Task.php';

/**
 * Class SetPluginVersion
 */
class SetPluginVersion extends Task
{
    /**
     * @var string
     */
    private $dir;

    /**
     * @var string
     */
    private $version;

    /**
     * @var string
     */
    private $pluginname;

    /**
     * @var string
     */
    private $constantname;

    /**
     * @param $dir
     */
    public function setDir($dir)
    {
        $this->dir = $dir;
    }

    /**
     * @param string $version
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * @param string $name
     */
    public function setPluginname($name)
    {
        $this->pluginname = $name;
    }

    /**
     * @param string $name
     */
    public function setConstantname($name)
    {
        $this->constantname = $name;
    }

    /**
     * The main method
     */
    public function main()
    {
        // Check if we have a stable version, before update the readme file
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $this->version))
        {
            $this->updateReadme();
        } else
        {
            $this->log('Unstable version detected. Readme.txt file will not be updated.');
        }

        $this->updatePluginFile();
        $this->updateConstantInFile($this->pluginname . '.php');
        $this->updateConstantInFile('/includes.php');
        $this->updateConstantInFile('/defines.php');
        $this->updateConstantInFile('/src/includes.php');
        $this->updateConstantInFile('/src/defines.php');
    }

    /**
     * Update version in the
     */
    private function updateReadme()
    {
        $this->updateFile(
            $this->dir . '/readme.txt',
            '/^(Stable tag: )([^\n]*)\n/m',
            'Stable tag: ' . $this->version . "\n",
            'Readme.txt updated.'
        );
    }

    /**
     * Updates a file
     *
     * @param string $path
     * @param string $pattern
     * @param string $replacement
     * @param string $logMessage
     */
    private function updateFile($path, $pattern, $replacement, $logMessage)
    {
        $path = str_replace('//', '/', $path);

        $fileContent = file_get_contents($path);

        // Update the content
        $fileContent = preg_replace($pattern, $replacement, $fileContent);

        // Store in the file
        file_put_contents($path, $fileContent);

        $this->log($logMessage);
    }

    private function updatePluginFile()
    {
        $this->updateFile(
            $this->dir . '/' . $this->pluginname . '.php',
            '/^(\s*\*\s*Version:\s*)([^\n]+)\n/m',
            ' * Version: ' . $this->version . "\n",
            $this->pluginname . '.php updated.'
        );
    }

    private function updateConstantInFile($filePath)
    {
        $path = $this->dir . $filePath;

        if (file_exists($path))
        {
            $path        = str_replace('//', '/', $path);
            $pattern     = '/(.*[\'"]' . $this->constantname . '[\'"],\s*[\'"])[^\'"]+([\'"])/m';
            $fileContent = file_get_contents($path);

            // preg_replace is not correctly replacing groups. So we do that manually.
            preg_match($pattern, $fileContent, $matches);

            $this->updateFile(
                $path,
                '/(.*[\'"]' . $this->constantname . '[\'"],\s*[\'"])[^\'"]+([\'"])/m',
                $matches[1] . $this->version . $matches[2],
                $filePath . ' updated.'
            );
        }
    }
}
