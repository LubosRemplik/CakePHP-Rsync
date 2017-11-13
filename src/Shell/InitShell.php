<?php
namespace Rsync\Shell;

use Cake\Console\Shell;
use Symfony\Component\Yaml\Yaml;

/**
 * Class: InitShell
 *
 * @author    Lubos Remplik <lubos@on-idle.com>
 * @copyright on-IDLE Ltd.
 * @license   https://www.opensource.org/licenses/mit-license.php MIT License
 * @link      https://www.on-idle.com
 *
 * @see Shell
 */
class InitShell extends Shell
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
        $parser->addArgument('target', [
            'help' => 'Target path to the yml file which should be generated.',
        ]);

        return $parser;
    }

    /**
     * Method: main
     *
     * @param string $target
     * @return void
     */
    public function main($target = 'rsync.yml')
    {
        $results = [];
        while (1 == 1) {
            $results[] = $this->add();
            $add = $this->in('Would you like to add another rsync task?', ['Y', 'n'], 'n');
            if ($add == 'n') {
                break;
            }
        }

        if (count($results) == 1) {
            $results = reset($results);
        }

        $yaml = Yaml::dump($results, 9999);
        $yaml = str_replace("''", '"', $yaml);
        if (file_put_contents($input, $yaml)) {
            $this->out(sprintf(
                '<success>Done</success> - %s file written. <error>Please review before use!</error>',
                $input
            ));
        }
    }

    /**
     * Method: add
     *
     * @return void
     */
    protected function add()
    {
        $config = [];
        $config['name'] = $this->in('Rsync task name');
        $config['ssh']['host'] = $this->in('SSH host');
        $config['ssh']['username'] = $this->in('SSH username');
        $config['ssh']['port'] = $this->in('SSH port', null, 22);
        $config['ssh']['privateKey'] = $this->in('SSH privateKey', null, '/home/lubos/.ssh/id_rsa');
        $config['src']['remote'] = $this->in('Source is remote?', ['Y', 'n'], 'Y') === 'Y' ? true : false;
        $config['src']['path'] = $this->in('Source path', null, 'htdocs');
        $config['dest']['remote'] = $this->in('Traget is remote?', ['Y', 'n'], 'n') === 'Y' ? true : false;
        $config['dest']['path'] = $this->in('Target path');

        return $config;
    }
}
