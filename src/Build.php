<?php

namespace Anton;

class Build
{
    public $steps = [];

    public $config = [];

    public $project = '';

    public $pipeline = '';

    public $workdir = '';

    public $status = 'failed';

    public $commits = 0;

    public function executeSteps()
    {
        foreach ($this->steps as $key => $value) {
            // @todo use step key for logile
            $logfile = $this->getLogFilename($value['identifier']);
            $this->executeRoboCommand($value['command'], $logfile);
            $this->existsLogfile($value['identifier']);
            $this->hasLogExit($value['identifier']);
            $this->hasLogException($value['identifier']);
            // @todo catch ssh errors ?
        }
    }

    public function checkoutBranch()
    {
        exec('cd ' . $this->workdir . ' && git checkout ' . $this->branch . ' 2>&1');
    }

    public function composerInstallRobo()
    {
        exec('cd ' . $this->workdir . ' && cd .robo && composer install 2>&1');
    }

    public function getWorkDir()
    {
        return $this->workdir;
    }

    public function updateRepo()
    {
        exec('cd ' . $this->workdir . ' && git pull' . ' 2>&1');
    }

    public function loadCommits()
    {
        $this->commits = exec('cd ' . $this->workdir . ' && git rev-list --count ' . $this->branch);
    }

    public function prepare()
    {
        $this->hasPipeline();
        $this->copyProjectConfig();
        // $this->addLog('Build started '.time());

        $this->checkoutBranch();
        $this->composerInstallRobo();

        // @todo add commits to log file for builds
        $this->updateRepo();
    }

    public function finish()
    {
        $this->executeRoboCheckBuild();
        $this->checkProjectLog('status');
        $this->setStatus('success');
        $this->save();
        echo 'Project trigger successfull.(' . $this->project . ' - ' . $this->pipeline . ')' . PHP_EOL;
    }

    public function __construct(string $project, string $pipeline)
    {
        try {
            $this->project = $project;
            $this->pipeline = $pipeline;
            $this->branch = $this->getBranch();
            $this->workdir = 'workspace/projects/' . $this->project;
            $this->helper = new \Anton\Config();
            $this->config = $this->helper->getProjectConfig($project);
            $this->logfolder = 'storage/logs/' . $this->project;

            $this->createLogFolder();
            $this->initSteps();
        } catch (\Exception $e) {
            echo $e->getMessage() . PHP_EOL;
            exit(0);
        }
    }

    public function run()
    {
        try {
            $this->prepare();
            $this->checkSteps();
            $this->executeSteps();
            $this->finish();
        } catch (\Exception $e) {
            echo $e->getMessage() . PHP_EOL;
            exit(0);
        }
    }

    public function save()
    {
        // @todo get build log
        // @todo update item

        // @bug? 2 jobs edit log at same time, lock file ?
        // file_put_contents('storage/builds/'.$this->project.'.log', \json_encode($this->log));
    }

    public function createLogFolder()
    {
        exec('mkdir -p ' . $this->logfolder);
    }

    public function getLogFolder()
    {
        return $this->logfolder;
    }

    public function getLogFilename(string $key)
    {
        return '../../../' . $this->getLogFolder() . '/' . $key . '.log';
    }

    public function existsLogfile(string $key)
    {
        $logfile = $this->workdir . '/' . $this->getLogFilename($key);
        if (!file_exists($logfile)) {
            throw new \Exception('Log not created. (' . $this->workdir . '/' . $logfile . ')');
        }
    }

    public function executeRoboCommand(string $command, $logfile)
    {
        echo 'Robo: '.$command.PHP_EOL;
        exec('cd ' . $this->workdir . ' && robo ' . $command . ' 2>&1 | tee ' . $logfile);
    }

    public function initSteps()
    {
        if (empty($this->config['steps'])) {
            throw new \Exception('Config has no steps. (' . $this->project . ')');
        }
        foreach ($this->config['steps'] as $step) {
            $this->steps[] = $step;
        }
    }

    public function executeRoboCheckBuild()
    {
        exec('cd ' . $this->workdir . ' && robo check:build 2>&1 | tee ../../../storage/logs/' . $this->project . '/status.log');
    }

    public function checkProjectLog(string $name)
    {
        $logfolder = 'storage/logs/' . $this->project;
        $log = file_get_contents($this->getLogFolder() . '/' . $name . '.log');
        $check =  trim(trim($log, PHP_EOL));
        if ($check !== 'success') {
            throw new \Exception('Step failed. (' . $this->project . ')');
        }
    }

    public function checkSteps()
    {
        if (count($this->steps) === null) {
            throw new \Exception('Build has no steps');
        }
    }

    public function setStatus(string $status)
    {
        // @todo save build?
        $this->status = $status;
    }

    public function hasPipeline()
    {
        $check = !empty($this->config['pipelines'][$this->pipeline]);

        if (!$check) {
            throw new \Exception('Pipeline unknown. (' . $pipeline . ')');
        }
    }

    public function getBranch(): string
    {
        if (!empty($this->config['pipelines'][$this->pipeline])) {
            return $this->config['pipelines'][$this->pipeline];
        }

        return 'master';
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function copyProjectConfig()
    {
        $config = $this->config;
        $branch = exec('git rev-parse --abbrev-ref HEAD');

        foreach ($config['pipelines'] as $key => $value) {
            if ($key == $this->pipeline) {
                $config['pipeline'] = $value;
                $config['pipeline']['name'] = $key;
                $server = $value['server'];
            }
        }

        if (empty($server)) {
            throw new \Exception('Pipeline has no Server.');
        }

        $found = false;

        foreach ($config['servers'] as $number => $item) {
            if ($number == $server) {
                $found = true;
                $config['server'] = $item;
            }
        }

        if (!$found) {
            throw new \Exception('Server not found.');
        }

        unset($config['servers']);
        unset($config['pipelines']);

        file_put_contents('workspace/projects/' . $this->project . '/anton-config.json', \json_encode($config, true));
    }

    public function getLogFileContent(string $key)
    {
        return trim(trim(file_get_contents($this->workdir . '/' . $this->getLogFilename($key)), PHP_EOL));
    }

    public function hasLogExit(string $key)
    {
        $log = $this->getLogFileContent($key);
        if (strpos($log, 'Exit code ') !== false) {
            throw new \Exception('Exit while deployment.(' . $key . ')');
        }
    }

    public function hasLogException(string $key)
    {
        $log = $this->getLogFileContent($key);
        if (strpos($log, '[error]') !== false) {
            throw new \Exception('Exception thrown while deployment. (Step: ' . $key . ')');
        }
    }
}
