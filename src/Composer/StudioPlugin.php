<?php

namespace Studio\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Illuminate\Support\Collection;
use Studio\Config\Config;
use Studio\Config\FileStorage;
use Symfony\Component\Finder\Finder;

class StudioPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        // ...
    }

    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => 'dumpAutoload',
        ];
    }

    public function dumpAutoload(Event $event)
    {
        $path = $event->getComposer()->getPackage()->getTargetDir();
        $studioFile = "{$path}studio.json";

        $config = $this->getConfig($studioFile);
        if ($config->hasPackages()) {
            $packages = $config->getPackages();
            $this->autoloadFrom($packages);
        }
    }

    /**
     * Instantiate and return the config object.
     *
     * @param string $file
     * @return Config
     */
    protected function getConfig($file)
    {
        return new Config(new FileStorage($file));
    }

    protected function autoloadFrom(array $directories)
    {
        // TODO: Handle "files"
        foreach (['classmap', 'namespaces', 'psr4'] as $type) {
            $projectFile = "vendor/composer/autoload_$type.php";
            $projectAutoloads = file_exists($projectFile) ? require $projectFile : [];

            $toMerge = array_diff_key(
                $this->getAutoloadersForType($directories, $type),
                $projectAutoloads
            );

            $this->mergeToEnd($projectFile, $toMerge);
        }
    }

    /**
     * @param array $directories
     * @param string $type
     * @return array
     */
    protected function getAutoloadersForType(array $directories, $type)
    {
        return (new Collection($directories))->map(function ($directory) use ($type) {
            return "$directory/vendor/composer/autoload_$type.php";
        })->filter('file_exists')->map(function ($file) {
            return require $file;
        })->reduce('array_merge', []);
    }

    protected function mergeToEnd($autoloadFile, array $newRules)
    {
        $contents = preg_replace_callback('/\),\s\);/', function () use ($newRules) {
            $start = "),\n\n    // @generated by Composer Studio (https://github.com/franzliedke/studio)\n\n";
            $end = "\n);\n";

            $lines = array_map(function ($value, $key) {
                return '    ' . var_export($key, true) . ' => ' . var_export($value, true) . ',';
            }, $newRules, array_keys($newRules));

            return $start . implode("\n", $lines) . $end;
        }, file_get_contents($autoloadFile));

        file_put_contents($autoloadFile, $contents);
    }
}
