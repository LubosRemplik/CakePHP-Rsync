<?php
namespace Rsync\Shell;

use Cake\Console\Shell;
use Symfony\Component\Yaml\Yaml;

/**
 * Migrate shell command.
 *
 * Converts old json configuration to new yaml
 */
class MigrateShell extends Shell
{

    /**
     * Manage the available sub-commands along with their arguments and help
     *
     * @see http://book.cakephp.org/3.0/en/console-and-shells.html#configuring-options-and-generating-help
     *
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function getOptionParser()
    {
        $parser = parent::getOptionParser();
        $parser->addArgument('input', [
            'help' => 'Input file with json configuration',
        ]);
        $parser->addArgument('output', [
            'help' => 'Output file with yaml configuration',
        ]);

        return $parser;
    }

    public function main($input, $output)
    {
        if (!file_exists($input)) {
            throw new \Exception('Missing json config file');
        }

        // read and format input config
        $inputConfig = json_decode(file_get_contents($input), true);
        if (!isset($inputConfig[0])) {
            $inputConfig = [$inputConfig];
        }

        // build output config
        $results = [];
        foreach ($inputConfig as $config) {
            $outputConfig = [];

            // name
            $outputConfig['name'] = isset($config['name'])
                ? $config['name']
                : 'Unknown';

            // remote 
            if (isset($config['pre-cmd']['remote']['host'])) {
                $remote = $config['pre-cmd']['remote'];
                if (isset($remote['key'])) {
                    $remote['privateKey'] = $remote['key'];
                    unset($remote['key']);
                }
                $outputConfig['ssh'] = $remote;
            }

            // pre rsync cmd
            if (isset($config['pre-cmd'])) {
                $cmd = $config['pre-cmd'];
                if (is_array($cmd)) {
                    $cmd = $cmd['exec'];
                }
                if (isset($config['pre-cmd']['remote']['host'])) {
                    $outputConfig['pre-rsync-cmd'][0]['remote'] = true;
                }
                $outputConfig['pre-rsync-cmd'][0]['command'] = $cmd;
            }

            // post rsync cmd
            if (isset($config['post-cmd'])) {
                $cmd = $config['post-cmd'];
                if (is_array($cmd)) {
                    $cmd = $cmd['exec'];
                }
                if (isset($config['post-cmd']['remote']['host'])) {
                    $outputConfig['post-rsync-cmd'][0]['remote'] = true;
                }
                $outputConfig['post-rsync-cmd'][0]['command'] = $cmd;
            }

            // src
            $src = $config['src'];
            if (strstr($src, ':')) {
                $src = explode(':', $src);
                $src = end($src);
                $outputConfig['src']['remote'] = true;
            }
            $outputConfig['src']['path'] = $src;

            // exclude
            if (isset($config['exclude'])) {
                $outputConfig['src']['exclude'] = $config['exclude'];
            }

            // dest
            $dest = $config['dest'];
            if (strstr($dest, ':')) {
                $dest = explode(':', $dest);
                $dest = end($dest);
                $outputConfig['dest']['remote'] = true;
            }
            $outputConfig['dest']['path'] = $dest;

            // copies
            if (isset($config['copies'])) {
                $outputConfig['dest']['copies'] = $config['copies'];
            }

            // params
            if (isset($config['params'])) {
                $outputConfig['params'] = $config['params'];
            }

            $results[] = $outputConfig;
        }

        $yaml = Yaml::dump($results, 9999);
        $yaml = str_replace("''", '"', $yaml);
        if (file_put_contents($output, $yaml)) {
            $this->out(sprintf(
                '<success>Done</success> - %s file written. <error>Please review before use!</error>', 
                $output
            ));
        }
    }
}
