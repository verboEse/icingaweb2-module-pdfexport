<?php
// Icinga PDF Export | (c) 2018 Icinga GmbH | GPLv2

namespace Icinga\Module\Pdfexport;

use Exception;
use Icinga\File\Storage\StorageInterface;
use Icinga\File\Storage\TemporaryLocalFileStorage;
use LogicException;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Message;
use React\ChildProcess\Process;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use function Ratchet\Client\connect;

class HeadlessChrome
{
    const DEBUG_ADDR_PATTERN = '/^DevTools listening on (ws:\/\/127\.0\.0\.1:\d+\/devtools\/browser\/[\w-]+)$/';

    /** @var string Path to the Chrome binary */
    protected $binary;

    /** @var string Target Url */
    protected $url;

    /** @var StorageInterface */
    protected $fileStorage;

    /**
     * Get the path to the Chrome binary
     *
     * @return  string
     */
    public function getBinary()
    {
        return $this->binary;
    }

    /**
     * Set the path to the Chrome binary
     *
     * @param   string  $binary
     *
     * @return  $this
     */
    public function setBinary($binary)
    {
        $this->binary = $binary;

        return $this;
    }

    /**
     * Get the target Url
     *
     * @return  string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Set the target Url
     *
     * @param   string  $url
     *
     * @return  $this
     */
    public function setUrl($url)
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Get the file storage
     *
     * @return  StorageInterface
     */
    public function getFileStorage()
    {
        if ($this->fileStorage === null) {
            $this->fileStorage = new TemporaryLocalFileStorage();
        }

        return $this->fileStorage;
    }

    /**
     * Set the file storage
     *
     * @param   StorageInterface  $fileStorage
     *
     * @return  $this
     */
    public function setFileStorage($fileStorage)
    {
        $this->fileStorage = $fileStorage;

        return $this;
    }

    /**
     * Render the given argument name-value pairs as shell-escaped string
     *
     * @param   array   $arguments
     *
     * @return  string
     */
    public static function renderArgumentList(array $arguments)
    {
        $list = [];

        foreach ($arguments as $name => $value) {
            if ($value !== null) {
                $value = escapeshellarg($value);

                if (! is_int($name)) {
                    if (substr($name, -1) === '=') {
                        $glue = '';
                    } else {
                        $glue = ' ';
                    }

                    $list[] = escapeshellarg($name) . $glue . $value;
                } else {
                    $list[] = $value;
                }
            } else {
                $list[] = escapeshellarg($name);
            }
        }

        return implode(' ', $list);
    }

    /**
     * Use the given HTML string as input
     *
     * @param   string  $html
     * @param   bool    $asFile
     *
     * @return  $this
     */
    public function fromHtml($html, $asFile = true)
    {
        if ($asFile) {
            $path = uniqid('icingaweb2-pdfexport-') . '.html';
            $storage = $this->getFileStorage();

            $storage->create($path, $html);

            $path = $storage->resolvePath($path, true);

            $this->setUrl("file://$path");
        } else {
            $this->setUrl('data:text/html,' . rawurlencode($html));
        }

        return $this;
    }

    /**
     * Export to PDF
     *
     * @param   $filename
     *
     * @return  string
     *
     * @throws  \Exception
     */
    public function toPdf($filename)
    {
        $path = uniqid('icingaweb2-pdfexport-') . $filename;
        $storage = $this->getFileStorage();

        $storage->create($path, '');

        $path = $storage->resolvePath($path, true);

        $context = (object) [];

        $loop = Factory::create();
        $context->loop = $loop;

        $chrome = new Process(join(' ', [
            escapeshellarg($this->getBinary()),
            static::renderArgumentList([
                '--headless',
                '--disable-gpu',
                '--no-sandbox',
                '--remote-debugging-port=0'
            ])
        ]));
        $context->chrome = $chrome;

        $chrome->start($loop);
        $chrome->stderr->on('data', function ($chunk) use ($context) {
            if (preg_match(self::DEBUG_ADDR_PATTERN, trim($chunk), $matches)) {
                connect($matches[1], [], [], $context->loop)->then(function (WebSocket $ws) use ($context) {
                    $context->ws = $ws;

                    $ws->once('message', function (Message $msg) use ($context) {
                        $result = $this->parseApiResponse($msg->getPayload());
                        $context->targetId = $result['targetId'];

                        $context->ws->once('message', function (Message $msg) use ($context) {
                            $result = json_decode($msg->getPayload(), true);
                            if (! isset($result['method']) || $result['method'] !== 'Target.attachedToTarget') {
                                throw new LogicException(sprintf('Unexpected response: %s', $msg->getPayload()));
                            } else {
                                $context->attachedTarget = $result['params'];
                            }

                            $context->ws->once('message', function (Message $msg) use ($context) {
                                $result = $this->parseApiResponse($msg->getPayload()); // Error handling
                                $context->ws->once('message', function (Message $msg) use ($context) {
                                    $result = $this->parseApiResponse($msg->getPayload());
                                    $context->result = $result['data'];

                                    $context->ws->close();
                                    $context->chrome->terminate();
                                });
                                $context->ws->send($this->renderApiCall('Page.printToPDF', [
                                    'transferMode'  => 'ReturnAsBase64'
                                ], $result['sessionId']));
                            });
                        });
                        $context->ws->send($this->renderApiCall('Target.attachToTarget', [
                            'targetId'  => $context->targetId
                        ]));
                    });
                    $ws->send($this->renderApiCall('Target.createTarget', [
                        'url'   => $this->getUrl()
                    ]));
                });
            }
        });

        $chrome->on('exit', function ($exitCode, $termSignal) {
            if ($exitCode) {
                throw new \Exception($exitCode);
            }
        });

        try {
            $loop->run();
        } catch (Exception $e) {
            $chrome->terminate();
            throw $e;
        }

        return $path;
    }

    private function renderApiCall($method, $options = null, $sessionId = null)
    {
        $data = [
            'id' => time(),
            'method' => $method,
            'params' => $options ?: []
        ];
        if ($sessionId !== null) {
            $data['sessionId'] = $sessionId;
        }

        return json_encode($data);
    }

    private function parseApiResponse($payload)
    {
        $data = json_decode($payload, true);
        if (! isset($data['id'])) {
            throw new LogicException(sprintf('Response has no id: %s', $payload));
        }

        if (isset($data['result'])) {
            return $data['result'];
        } elseif (isset($data['error'])) {
            throw new Exception(sprintf(
                'Error response (%s): %s',
                $data['error']['code'],
                $data['error']['message']
            ));
        } else {
            throw new Exception(sprintf('Unknown response received: %s', $payload));
        }
    }

    /**
     * Get the major version number of Chrome or false on failure
     *
     * @return  int|false
     *
     * @throws  \Exception
     */
    public function getVersion()
    {
        $command = new ShellCommand(
            escapeshellarg($this->getBinary()) . ' ' . static::renderArgumentList(['--version']),
            false
        );

        $output = $command->execute();

        if ($command->getExitCode() !== 0) {
            throw new \Exception($output->stderr);
        }

        if (preg_match('/\s(\d+)\.[\d\.]+\s/', $output->stdout, $match)) {
            return (int) $match[1];
        }

        return false;
    }
}
