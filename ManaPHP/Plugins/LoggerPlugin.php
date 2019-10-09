<?php
namespace ManaPHP\Plugins;

use ManaPHP\Exception\AbortException;
use ManaPHP\Logger\Log;
use ManaPHP\Plugin;

class LoggerPluginContext
{
    /**
     * @var bool
     */
    public $enabled = true;

    /**
     * @var string
     */
    public $file;

    public $logs = [];
}

/**
 * Class LoggerPlugin
 * @package ManaPHP\Plugins
 * @property-read \ManaPHP\Plugins\LoggerPluginContext $_context
 */
class LoggerPlugin extends Plugin
{
    /**
     * @var string
     */
    protected $_template = '@manaphp/Plugins/LoggerPlugin/Template.html';

    public function __construct()
    {
        $this->eventsManager->attachEvent('request:begin', [$this, 'onRequestBegin']);
        $this->eventsManager->attachEvent('logger:log', [$this, 'onLoggerLog']);
        $this->eventsManager->attachEvent('request:end', [$this, 'onRequestEnd']);
    }

    public function onRequestBegin()
    {
        $context = $this->_context;

        if (($logger = $this->request->get('_logger', '')) && preg_match('#^([\w/]+)\.(html|json|txt)$#', $logger, $match)) {
            $context->enabled = false;
            $file = '@data/logger' . $match[1] . '.json';
            if ($this->filesystem->fileExists($file)) {
                $ext = $match[2];
                $json = $this->filesystem->fileGet($file);
                if ($ext === 'html') {
                    $this->response->setContent(strtr($this->filesystem->fileGet($this->_template), ['LOGGER_DATA' => $json]));
                } elseif ($ext === 'txt') {
                    $content = '';
                    foreach (json_parse($json) as $log) {
                        $content .= strtr('[time][level][category][location] message', $log) . PHP_EOL;
                        $this->response->setContent($content)->setContentType('text/plain;charset=UTF-8');
                    }
                } else {
                    $this->response->setJsonContent($json);
                }

                throw new AbortException();
            }
        } elseif (strpos($this->request->getServer('HTTP_USER_AGENT'), 'ApacheBench') !== false) {
            $context->enabled = false;
        } else {
            $context->enabled = true;
            $this->logger->info($this->request->getGlobals()->_REQUEST, 'globals.request');
            $context->file = date('/ymd/His_') . $this->random->getBase(32);
            $this->response->setHeader('X-Logger-Link', $this->getUrl());
        }
    }

    public function onLoggerLog(/** @noinspection PhpUnusedParameterInspection */ $logger, Log $log)
    {
        $context = $this->_context;

        if ($context->enabled) {
            $context->logs[] = [
                'time' => date('H:i:s.', $log->timestamp) . sprintf('%.03d', ($log->timestamp - (int)$log->timestamp) * 1000),
                'category' => $log->category,
                'location' => "$log->file:$log->line",
                'level' => $log->level,
                'message' => $log->message,
            ];
        }
    }

    public function save($file)
    {
        $this->filesystem->filePut($file, json_stringify($this->_context->logs, JSON_PRETTY_PRINT));
    }

    public function onRequestEnd()
    {
        $context = $this->_context;

        if ($context->enabled) {
            $this->save('@data/logger/' . $context->file . '.json');
        }
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        $context = $this->_context;

        return $this->router->createUrl('/?_logger=' . $context->file . '.html', true);
    }

    public function dump()
    {
        $data = parent::dump();

        $data['_context']['logs'] = '***';

        return $data;
    }
}