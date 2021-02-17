<?php
namespace Rsync\Shell;

use Cake\Console\Shell;
use Cake\Datasource\ConnectionManager;
use Cake\Filesystem\Folder;
use Cake\Utility\Text;
use phpseclib3\Crypt\RSA;
use phpseclib3\Net\SSH2;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Rsync shell command.
 */
class RsyncShell extends Shell
{

    /**
     * Attribute: config
     *
     * @var mixed
     */
    protected $config = null;

    /**
     * Attribute: ssh
     *
     * @var mixed
     */
    protected $ssh = null;

    /**
     * Attribute: exitStatus
     *
     * @var mixed
     */
    protected $exitStatus = null;

    /**
     * Attribute: output
     *
     * @var mixed
     */
    protected $output = null;

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
        $parser->addArgument('file', [
            'help' => 'File with YAML configuration',
            'required' => false,
        ]);
        $parser->addOption('force', [
            'short' => 'f',
            'help' => 'Disable command execution prompt',
            'boolean' => true,
        ]);
        $parser->addOption('task', [
            'short' => 't',
            'help' => 'Run only specific task',
        ]);
        $parser->addOption('disable-pre-post', [
            'help' => 'Do not execute pre and post rsync commands.',
            'boolean' => true,
        ]);
        $parser->addOption('dest-path', [
            'help' => 'Possible to rewrite destination path (default: ~/:config-stem/:task-name/)',
        ]);
        $parser->addOption('copies', [
            'help' => 'If set, rewrites copies config from yaml',
        ]);

        return $parser;
    }

    /**
     * Method: main
     *
     * Reads yaml file and executes given rsync commands
     *
     * @param string $file Yaml file
     * @return bool|void
     */
    public function main($file = null)
    {
        // microtime for script time
        $scriptStart = microtime(true);

        if (!file_exists($file)) {
            $file = getcwd() . DS . 'rsync.yml';
        }

        if (!file_exists($file)) {
            throw new \Exception('Missing rsync.yml file with config');
        }

        try {
            $rsyncs = Yaml::parse(file_get_contents($file));
        } catch (ParseException $e) {
            $message = sprintf("Unable to parse the YAML string: %s", $e->getMessage());
            $this->error($message);
        }

        if (!isset($rsyncs[0])) {
            $rsyncs = [$rsyncs];
        }

        foreach ($rsyncs as $rsync) {
            // microtime for total time
            $start = microtime(true);

            // formating config
            $rsync['file'] = $file;
            $this->config($rsync);

            // continue when task option != name
            if (isset($this->params['task']) && $this->params['task'] != $this->config['name']) {
                continue;
            }

            // info about task when started
            $this->hr();
            $name = $this->config['name'];
            if (empty($name)) {
                $name = Text::uuid();
            }
            $message = sprintf(
                '<info>Rsync task %sstarted</info>',
                $name ? sprintf('"%s" ', $name) : ''
            );
            $this->out($message);
            $this->hr();

            // try remote, show error and continue with others when not able to connect
            if (isset($this->config['ssh']['host']) && !$this->sshConnect()) {
                $message = sprintf('Task: %s; Unable to ssh connect, continue with another task.', $name);
                $this->err($message);
                continue;
            }

            // execute pre rsync commands
            if (isset($this->config['pre-rsync-cmd']) && !$this->params['disable-pre-post']) {
                $rsyncFailed = false;
                foreach ($this->config['pre-rsync-cmd'] as $cmd) {
                    if ($this->rsyncCmd($cmd) === false) {
                        $rsyncFailed = true;
                    }
                }
                if ($rsyncFailed) {
                    $message = sprintf('Pre rsync command(s) %s failed, continue with another task.', $name);
                    $this->err($message);
                    continue;
                }
            }

            // create destination path
            $remote = $this->config['dest']['remote'];
            $this->execute(
                sprintf('mkdir -p %s', $this->config['dest']['path']),
                compact('remote')
            );

            // if copies are required create and change path to new subfolder
            $folders = false;
            if ($this->config['dest']['copies'] > 1) {
                $folders = $this->execute(
                    sprintf('cd %s && ls -1d */ 2>/dev/null', $this->config['dest']['path']),
                    ['remote' => $this->config['dest']['remote']]
                );
                if (!empty($folders)) {
                    sort($folders);
                    $this->config['params'][] = sprintf("--link-dest='../%s'", end($folders));
                }
                $this->config['dest']['path'] .= date('ymd_His') . DS;
            }

            // setting exclude
            if ($this->config['src']['exclude']) {
                $exclude = $this->config['src']['exclude'];
                if (!is_array($exclude)) {
                    $exclude = [$exclude];
                }
                foreach ($exclude as $path) {
                    $this->config['params'][] = sprintf("--exclude '%s'", $path);
                }
            }

            // params
            $this->config['params'] = implode(' ', $this->config['params']);

            // setting ssh
            $isRemote = ($this->config['src']['remote'] || $this->config['dest']['remote']);
            if (!strstr($this->config['params'], '--rsh') && $isRemote) {
                $this->config['params'] .= sprintf(
                    " --rsh='ssh -p%s -i %s'",
                    $this->config['ssh']['port'],
                    $this->config['ssh']['privateKey']
                );
            }

            // execute rsync
            $command = sprintf(
                'rsync %s %s %s',
                $this->config['params'],
                $this->getPath('src'),
                $this->getPath('dest')
            );
            $output = $this->execute($command, ['prompt' => true, 'showBuffer' => true]);

            if ($output === false) {
                $message = sprintf('Rsync %s failed. Exit code %s.', $name, $this->exitStatus);
                $this->err($message);
            }
            // delete only when multiple folders
            if ($folders) {
                $count = count($folders);
                while ($count >= $this->config['dest']['copies']) {
                    $folder = array_shift($folders);
                    $count = count($folders);
                    $command = sprintf(
                        'cd %s && rm -Rf ../%s',
                        $this->config['dest']['path'],
                        $folder
                    );
                    $remote = $this->config['dest']['remote'];
                    $prompt = true;
                    $output = $this->execute(
                        $command,
                        compact('remote', 'prompt')
                    );
                }
            }

            // execute post rsync commands
            if (isset($this->config['post-rsync-cmd']) && !$this->params['disable-post-post']) {
                $rsyncFailed = false;
                foreach ($this->config['post-rsync-cmd'] as $cmd) {
                    if ($this->rsyncCmd($cmd) === false) {
                        $rsyncFailed = true;
                    }
                }
                if ($rsyncFailed) {
                    $message = sprintf('Post rsync command(s) %s failed, continue with another task.', $name);
                    $this->err($message);
                    continue;
                }
            }

            // info about task when finished
            $this->hr();
            $name = $this->config['name'];
            $message = sprintf(
                '<success>Rsync task %sfinished in %0.2fs</success>',
                $name ? sprintf('"%s" ', $name) : '',
                microtime(true) - $start
            );
            $this->out($message);
            $this->hr();
        }
        $message = sprintf(
            '<success>Done. Script execution time %0.2fs</success>',
            microtime(true) - $scriptStart
        );
        $this->out($message);
    }

    /**
     * Method: config
     *
     * Adds some required defaults
     *
     * @param array $config Unformatted array with config.
     * @return array
     */
    protected function config($config)
    {
        // throw exception when missing src
        if (!isset($config['src'])) {
            throw new \Exception('Missing rsync src (source) config');
        }

        // format config, add defaults
        $defaultParams = ["-aW --inplace --delete --stats --info=progress2"];
        if ($this->params['verbose']) {
            $defaultParams[] = '-v';
        }
        $config += [
            'name' => false,
            'params' => $defaultParams,
            'ssh' => [],
            'dest' => [],
        ];
        $config['ssh'] += [
            'port' => 22,
            'timeout' => 5 * 60,
        ];
        if (!is_array($config['src'])) {
            $config['src'] = ['path' => $config['src']];
        }
        $config['src'] += [
            'exclude' => [],
            'remote' => false,
        ];
        if (!is_array($config['dest'])) {
            $config['dest'] = ['path' => $config['dest']];
        }
        $config['dest'] += [
            'copies' => 1,
            'remote' => false,
        ];
        // adjust copies
        if (!empty($this->params['copies'])) {
            $config['dest']['copies'] = $this->params['copies'];
        }
        // adjust destination path
        $configStem = explode('.', basename($config['file']));
        array_pop($configStem);
        $configStem = implode('.', $configStem);
        if (!empty($this->params['dest-path'])) {
            $dest = $this->params['dest-path'];
            $insertData = ['config-stem' => $configStem];
            if ($config['name']) {
                $insertData = ['task-name' => $config['name']];
            }
            $dest = Text::insert($dest, $insertData);
            $config['dest']['path'] = $dest;
        }

        if (empty($config['dest']['path'])) {
            $config['dest']['path'] = '~/';
            $config['dest']['path'] .= sprintf('%s/', $configStem);
            if ($config['name']) {
                $config['dest']['path'] .= sprintf('%s/', $config['name']);
            }
        }
        if (!is_array($config['params'])) {
            $config['params'] = [$config['params']];
        }
        if ($config['src']['remote'] == false && strstr($config['src']['path'], ':latest')) {
            $folder = explode(':latest', $config['src']['path'])[0];
            $dir = new Folder($folder);
            $latest = end($dir->read()[0]);
            $latest = substr($latest, 0, strlen($latest) - 1); // removing ending slash
            $config['src']['path'] = str_replace(':latest', $latest, $config['src']['path']);
        }
        if (isset($config['pre-rsync-cmd'])) {
            foreach ($config['pre-rsync-cmd'] as $key => $cmd) {
                if (!isset($cmd['command'])) {
                    $cmd = ['command' => $cmd];
                }
                $cmd += [
                    'remote' => false,
                ];
                $config['pre-rsync-cmd'][$key] = $cmd;
            }
        }
        if (isset($config['post-rsync-cmd'])) {
            foreach ($config['post-rsync-cmd'] as $key => $cmd) {
                if (!isset($cmd['command'])) {
                    $cmd = ['command' => $cmd];
                }
                $cmd += [
                    'remote' => false,
                ];
                $config['post-rsync-cmd'][$key] = $cmd;
            }
        }

        // replacing tidle with home directory
        // only privateKey and local pre-rsync-cmd, post-rsync-cmd, src, dest
        $home = getenv("HOME");
        if (isset($config['ssh']['privateKey'])) {
            $config['ssh']['privateKey'] = str_replace('~', $home, $config['ssh']['privateKey']);
        }
        if ($config['src']['remote'] === false) {
            $config['src']['path'] = str_replace('~', $home, $config['src']['path']);
        }
        if ($config['dest']['remote'] === false) {
            $config['dest']['path'] = str_replace('~', $home, $config['dest']['path']);
        }
        if (isset($config['pre-rsync-cmd'])) {
            foreach ($config['pre-rsync-cmd'] as $key => $cmd) {
                if ($cmd['remote'] === false) {
                    $cmd['command'] = str_replace('~', $home, $cmd['command']);
                }
                $config['pre-rsync-cmd'][$key] = $cmd;
            }
        }
        if (isset($config['post-rsync-cmd'])) {
            foreach ($config['post-rsync-cmd'] as $key => $cmd) {
                if ($cmd['remote'] === false) {
                    $cmd['command'] = str_replace('~', $home, $cmd['command']);
                }
                $config['post-rsync-cmd'][$key] = $cmd;
            }
        }

        $this->config = $config;

        return $this->config;
    }

    /**
     * Method: execute
     *
     * Executes command either locally or ssh remote
     *
     * Options:
     * - remote: (bool) execute at ssh remote
     * - prompt: (bool) to ask before execution
     *
     * @param string $command Command to execute
     * @param array $options See above
     * @return bool|array Array $output or false when failed
     */
    protected function execute($command, $options = [])
    {
        $options += [
            'remote' => false,
            'prompt' => false,
            'showBuffer' => false,
        ];
        if ($options['prompt'] && !$this->params['force']) {
            $this->hr();
            $this->out(sprintf(
                '<warning>%s</warning> (remote: %s)',
                $command,
                $options['remote'] ? '<error>yes</error>' : 'no'
            ), 1, Shell::QUIET);
            $this->hr();
            $execute = $this->in('Are you sure you want to execute:', ['Y', 'n'], 'n');
            if ($execute == 'n') {
                return false;
            }
        }

        if ($options['remote']) {
            try {
                $ssh = $this->sshConnect();
                $output = $ssh->exec($command);
                $code = $ssh->getExitStatus();
            } catch (\Exception $e) {
                $message = sprintf("Unable to execute SSH command: %s; Message: %s", $command, $e->getMessage());
                $this->err($message);
            }
        } else {
            try {
                $process = new Process($command);
                $process->setTimeout(false);
                $process->run(function ($type, $buffer) use ($options) {
                    if ($options['showBuffer']) {
                        $this->out($buffer, 0);
                    }
                });
                $output = $process->getOutput();
                $code = $process->getExitCode();
            } catch (\Exception $e) {
                $message = sprintf("Unable to execute local command: %s; Message: %s", $command, $e->getMessage());
                $this->err($message);
            }
        }

        if (!empty($output)) {
            $output = explode(PHP_EOL, trim($output));
        }

        $this->exitStatus = $code;
        $this->output = $output;

        return $code === 0
            ? $output
            : false;
    }

    /**
     * Method: getPath
     *
     * Get either local or ssh path
     *
     * @param string $type src|dest
     * @return string
     */
    protected function getPath($type)
    {
        if (!isset($this->config[$type])) {
            throw new \Exception('Invalid type');
        }
        $path = $this->config[$type]['path'];
        if ($this->config[$type]['remote']) {
            $path = sprintf(
                '%s@%s:%s',
                $this->config['ssh']['username'],
                $this->config['ssh']['host'],
                $path
            );
        }

        return $path;
    }

    /**
     * Method: rsyncCmd
     *
     * Formats and executes pre and post rsync commands
     *
     * @param string|array $cmd Command
     * @return bool
     */
    protected function rsyncCmd($cmd)
    {
        if (!is_array($cmd)) {
            $cmd = ['command' => $cmd];
        }
        $remote = false;
        if (isset($cmd['remote'])) {
            $remote = $cmd['remote'];
        }

        if (!$remote) {
            // insert variables into mysql commands
            if (strstr($cmd['command'], 'mysql')) {
                $connection = ConnectionManager::get('default');
                $cmd['command'] = Text::insert($cmd['command'], $connection->config());
            }
        }

        $prompt = true;
        $output = $this->execute($cmd['command'], compact('remote', 'prompt'));

        if ($this->exitStatus !== 0) {
            $out = sprintf(
                '<error>Exit code %s:</error> for "%s" command (remote: %s)',
                $this->exitStatus,
                $cmd['command'],
                $remote ? '<error>yes</error>' : 'no'
            );
            $this->out($out);
        }

        if ($this->output) {
            foreach ($this->output as $out) {
                $this->out($out);
            }
        }

        return $output === false ? false : true;
    }

    /**
     * Method: sshConnect
     *
     * Connect to SSH via config property
     *
     * @return bool|\phpseclib3\Net\SSH2 $ssh SSH object, logged in
     */
    protected function sshConnect()
    {
        $this->ssh = null;

        // fix for home path directory
        if (isset($this->config['ssh']['privateKey'])) {
            $this->config['ssh']['privateKey'] = str_replace('~/', getenv("HOME"), $this->config['ssh']['privateKey']);
        }

        if (!isset($this->config['ssh']['host'])) {
            throw new \Exception('Missing SSH host');
        }
        if (!isset($this->config['ssh']['username'])) {
            throw new \Exception('Missing SSH username');
        }
        if (!isset($this->config['ssh']['privateKey']) || !file_exists($this->config['ssh']['privateKey'])) {
            throw new \Exception('Missing SSH privateKey');
        }

        $ssh = new SSH2($this->config['ssh']['host'], $this->config['ssh']['port']);
        $ssh->setTimeout($this->config['ssh']['timeout']);

        $key = new RSA();
        $key->loadKey(file_get_contents($this->config['ssh']['privateKey']));
        try {
            $login = $ssh->login($this->config['ssh']['username'], $key);
            $this->ssh = $login ? $ssh : false;
        } catch (\RuntimeException $e) {
            $message = sprintf("SSH login failed; Message: %s", $e->getMessage());
            $this->err($message);
        }

        return $this->ssh;
    }
}
