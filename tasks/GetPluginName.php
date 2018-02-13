<?php
require_once 'phing/Task.php';

/**
 * Class GetPluginName
 */
class GetPluginName extends Task
{
    /**
     * @var string
     */
    protected $dir;

    /**
     * @var string
     */
    protected $property;

    /**
     * @param $dir
     */
    public function setDir($dir)
    {
        $this->dir = $dir;
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
        $json = trim(file_get_contents($this->dir . '/composer.json'));
        $json = json_decode($json);

        $name = explode('/', $json->name);

        if (count($name) > 1)
        {
            $name = $name[count($name) - 1];
        } else
        {
            $name = $name[0];
        }

        $this->project->setProperty($this->property, $name);
    }
}