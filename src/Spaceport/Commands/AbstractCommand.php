<?php

namespace Spaceport\Commands;

use Humbug\SelfUpdate\Exception\HttpRequestException;
use Humbug\SelfUpdate\Updater;
use Spaceport\Model\Shuttle;
use Spaceport\Traits\IOTrait;
use Spaceport\Traits\TwigTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;

abstract class AbstractCommand extends Command
{
    CONST DOCKER_COMPOSE_FILE_NAME = "docker-compose.yml";
    CONST DOCKER_COMPOSE_MAC_FILE_NAME = "docker-compose-mac.yml";
    CONST DINGHY_CERTS_DIR_PATH = "~/.dinghy/certs/";
    CONST PHAR_PACKAGE_NAME = "kunstmaan/spaceport";
    CONST PHAR_FILE_NAME = "spaceport.phar";

    use TwigTrait;
    use IOTrait;

    /**
     * @var Shuttle
     */
    protected $shuttle;

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setUpIO($input, $output);
        $this->setUpTwig($output);

        $this->showLogo();
        $this->io->title("Executing " . get_class($this));

        $this->shuttle = new Shuttle();

        $this->checkDockerDaemonIsRunning();
        $this->isApacheRunning();

        $this->doPreExecute($output);
        $this->doExecute($input, $output);
    }

    protected function runCommand($command, $timeout = 180, $env = [], $quiet = false)
    {
        $this->logCommand($command);
        $env = array_replace($_ENV, $_SERVER, $env);
        $process = new Process($command, null, $env);
        $process->setTimeout($timeout);
        $process->run(function ($type, $buffer) {
            if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_VERBOSE) {
                strlen($type); // just to get rid of the scrutinizer error... sigh
                echo $buffer;
            }
        });
        if (!$process->isSuccessful()) {
            if (!$quiet) {
                $this->logError($process->getErrorOutput());
            }

            return false;
        }

        return trim($process->getOutput());
    }

    /**
     * Check if the project is ready for docker.
     * Logs an error if the project is not ready for docker.
     *
     * @param bool $quiet
     * @return true
     */
    protected function isDockerized($quiet = false)
    {
        $dockerFile = $this->getDockerComposeFileName();
        if (!file_exists($dockerFile)) {
            if (!$quiet) {
                $this->logError("There is no docker-compose file present. Run `spaceport init` first");
            }

            return false;
        }

        return true;
    }

    /**
     * Check is OS is MacOs
     *
     * @return bool
     */
    protected function isMacOs()
    {
        return \PHP_OS === 'Darwin';
    }

    /**
     * Check if nfsd is running
     *
     * @return bool
     */
    protected function isNfsdRunning()
    {
        // Command throws exit code so we need to do the process manually
        $process = new Process('sudo nfsd status | grep "not running"');
        $process->start();
        $process->wait();

        return empty($process->getOutput());
    }

    protected function getContainerId($name)
    {
        return $this->runCommand('docker container ls -a -f name='.$name.' -q');
    }

    protected function getMysqlContainerId()
    {
        return $this->getContainerId($this->shuttle->getName()."_mysql");
    }

    protected function getProxyContainerId()
    {
        return $this->getContainerId("http-proxy");
    }

    protected function isProxyRunning($containerId = null)
    {
        if (null === $containerId) {
            $containerId = $this->getProxyContainerId();
        }

        if (empty($containerId)) {
            return false;
        }

        $containerRunning = $this->runCommand('docker container inspect -f {{.State.Running}} ' . $containerId);
        return $containerRunning !== 'false';
    }

    /**
     * Check if dinghy ssl certs already exist and ask to enable ssl if they don't
     */
    protected function setDinghySSLCerts()
    {
        $home = getenv("HOME");
        if (file_exists($home . "/.dinghy/certs/" . $this->shuttle->getApacheVhost() . ".crt") && file_exists($home . "/.dinghy/certs/" . $this->shuttle->getApacheVhost() . ".key")) {
            $this->shuttle->setSslEnabled(true);

            return;
        }

        if ($this->io->confirm('Do you want to enable SSL for your Apache vhost ?', true)) {
            $this->createSSLCerts();
            $this->shuttle->setSslEnabled(true);
        } else {
            $this->shuttle->setSslEnabled(false);
        }
    }

    protected function createSSLCerts()
    {
        //Check if dinghy dir exists
        if (!file_exists(self::DINGHY_CERTS_DIR_PATH)) {
            $this->runCommand("mkdir -p " . self::DINGHY_CERTS_DIR_PATH);
        }
        $sslFilesPath = $this->getSSLFilesPaths();
        if ($sslFilesPath) {
            foreach (["crt", "key"] as $extension) {
                $this->runCommand("sudo -s -p \"Please enter your sudo password:\" cp " . $sslFilesPath[$extension] . " " . self::DINGHY_CERTS_DIR_PATH . "dinghy." . $extension . " 2>/dev/null", null, [], true);
                $this->runCommand("sudo -s -p \"Please enter your sudo password:\" ln -sf dinghy." . $extension . " " . self::DINGHY_CERTS_DIR_PATH . $this->shuttle->getApacheVhost() . "." . $extension);
            }
        }

    }

    protected function getDockerComposeFileName()
    {
        return $this->isMacOs() ? self::DOCKER_COMPOSE_MAC_FILE_NAME : self::DOCKER_COMPOSE_FILE_NAME;
    }

    protected function getDockerComposeFullFileName()
    {
        $currentWorkDir = getcwd();
        $dockerFilePath = $currentWorkDir . DIRECTORY_SEPARATOR;
        $dockerFile = $this->getDockerComposeFileName();

        return $dockerFilePath . $dockerFile;
    }

    /**
     * Check that the current user is owner of the files. If not this might give problems with the NFS mount
     * because the local user is mapped to the root user in the docker container.
     * This function only checks in the root directory.
     */
    protected function isOwnerOfFilesInDirectory()
    {
        $uid = $this->runCommand('id -u');
        $path = getcwd();
        $files = scandir($path);
        foreach ($files as $file) {
            if (!in_array($file, ['.', '..'])) {
                $info = stat( $path . '/' . $file);
                $fileUid = $info[4];
                if ($fileUid != $uid) {
                    $this->logWarning("You are not the filesystem owner of some files/directories in the project. This might give problems with the NFS mount.");
                    return false;
                }
            }
        }

        return true;
    }

    protected function runComposerInstall()
    {
        if (file_exists("composer.json") && !file_exists("vendor")) {
            $this->logStep("composer.json file found but no vendor dir. Trying to run composer install");
            $this->runCommand("composer install");
        }
    }

    /**
     * Ask about the paths for the ssl crt and key files.
     * Returns false if a path does not exists. Otherwise it returns a key value array of the crt and key path.
     *
     * @return array|bool
     */
    private function getSSLFilesPaths()
    {
        $sslFilesPaths = [];
        foreach (["crt", "key"] as $extension) {
            $question = new Question("What is the location of the SSL " . $extension . " file ?", self::DINGHY_CERTS_DIR_PATH . "dinghy." . $extension);
            $sslFilePath = $this->io->askQuestion($question);
            // Replace ~ with the home dir so file_exists works correctly
            $sslFilePath = str_replace("~", getenv("HOME"), $sslFilePath);
            if (!file_exists($sslFilePath)) {
                $this->logError("The path " . $sslFilePath . " does not exist");

                exit(1);
            } else {
                $sslFilesPaths[$extension] = $sslFilePath;
            }
        }

        return $sslFilesPaths;
    }

    private function checkDockerDaemonIsRunning()
    {
        if ($this->isMacOs()) {
            $output = $this->runCommand('ps aux | grep docker | grep -v \'grep\' | grep -v \'com.docker.vmnetd\'', null, [], true);
            if (empty($output)) {
                $this->logError("Docker daemon is not running. Start Docker first before using spaceport");

                exit(1);
            }
        } else {
            //TODO
            $output = $this->runCommand('ps aux | grep docker | grep -v \'grep\' | grep -v \'com.docker.vmnetd\'', null, [], true);
            if (empty($output)) {
                $this->logError("Docker daemon is not running. Start Docker first before using spaceport");

                exit(1);
            }
        }
    }

    /**
     * Check if apache is running, if so, exit when running spaceport start, otherwise just notify
     */
    private function isApacheRunning()
    {
        $process = new Process('pgrep httpd');
        $process->start();
        $process->wait();
        $processes = $process->getOutput();
        if (!empty($processes)) {
            // Only exit and show error on spaceport start
            if ($this instanceof StartCommand) {
                $this->logError('Apache seems to be running. Please shutdown apache and re-run your command.');
                exit(1);
            }
        }
    }

    private function doPreExecute()
    {
        $updater = new Updater(null, false, Updater::STRATEGY_GITHUB);
        $updater->getStrategy()->setPackageName(self::PHAR_PACKAGE_NAME);
        $updater->getStrategy()->setPharName(self::PHAR_FILE_NAME);
        $updater->getStrategy()->setCurrentLocalVersion($this->getApplication()->getVersion());
        try {
            if ($this->getName() !== 'self-update' && $updater->hasUpdate()) {
                $this->logWarning("There is a new version of spaceport available. Run the self-update command to update");
            }
        } catch (\Exception $e) {
            //Ignore errors
        }
    }

    abstract protected function doExecute(InputInterface $input, OutputInterface $output);
}
