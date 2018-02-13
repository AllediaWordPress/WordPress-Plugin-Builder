<?php
require_once 'phing/Task.php';

/**
 * Class GetPluginVersion
 */
class GetPluginVersion extends Task
{
    /**
     * @var string
     */
    protected $file;

    /**
     * @var string
     */
    protected $property;

    /**
     * @param $file
     */
    public function setFile($file)
    {
        $this->file = $file;
    }

    /**
     * @param string $property
     */
    public function setProperty($property)
    {
        $this->property = $property;
    }

    /**
     * The main method
     */
    public function main()
    {
        $fileContent = trim(file_get_contents($this->file));

        preg_match('/Version:\s*([0-9\.a-z\-]*)/i', $fileContent, $matches);

        $this->project->setProperty($this->property, $matches[1]);
    }
}