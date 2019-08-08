<?php
namespace ManaPHP\Rpc\Server\Adapter;

use ManaPHP\Component;
use ManaPHP\Exception\NotSupportedException;
use ManaPHP\Rpc\ServerInterface;

/**
 * Class Fpm
 * @package ManaPHP\Rpc\Server\Adapter
 * @property-read \ManaPHP\Http\RequestInterface $request
 */
class Fpm extends Component implements ServerInterface
{
    /**
     * @var \ManaPHP\Rpc\Server\HandlerInterface
     */
    protected $_handler;

    protected function _prepareGlobals()
    {
        if (!isset($_GET['_url']) && ($pos = strpos($_SERVER['PHP_SELF'], '/index.php/')) !== false) {
            $_GET['_url'] = $_REQUEST['_url'] = '/index' . substr($_SERVER['PHP_SELF'], $pos + 10);
        }

        if (!$_POST && isset($_SERVER['REQUEST_METHOD']) && !in_array($_SERVER['REQUEST_METHOD'], ['GET', 'OPTIONS'], true)) {
            $data = file_get_contents('php://input');

            if (isset($_SERVER['CONTENT_TYPE'])
                && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
                $_POST = json_decode($data, true, 16);
            } else {
                parse_str($data, $_POST);
            }

            if (is_array($_POST)) {
                /** @noinspection AdditionOperationOnArraysInspection */
                $_REQUEST = $_POST + $_GET;
            } else {
                $_POST = [];
            }
        }

        $this->request->setRequestId($_SERVER['HTTP_X_REQUEST_ID'] ?? null);

        $globals = $this->request->getGlobals();

        $globals->_GET = $_GET;
        $globals->_POST = $_POST;
        $globals->_REQUEST = $_REQUEST;
        $globals->_SERVER = $_SERVER;

        $GLOBALS['globals'] = $globals;
    }

    /**
     * @param \ManaPHP\Http\Server\HandlerInterface $handler
     *
     * @return static
     */
    public function start($handler)
    {
        $this->_prepareGlobals();

        $handler->handle();

        return $this;
    }

    /**
     * @param \ManaPHP\Http\ResponseContext $response
     *
     * @return static
     */
    public function send($response)
    {
        header('HTTP/1.1 ' . $response->status_code . ' ' . $response->status_text);

        foreach ($response->headers as $header => $value) {
            if ($value !== null) {
                header($header . ': ' . $value);
            } else {
                header($header);
            }
        }

        if ($response->cookies) {
            throw new NotSupportedException('rpc not support cookies');
        }

        $server = $this->request->getGlobals()->_SERVER;

        header('X-Request-Id: ' . $this->request->getRequestId());
        header('X-Response-Time: ' . sprintf('%.3f', microtime(true) - $server['REQUEST_TIME_FLOAT']));

        if ($response->file) {
            throw new NotSupportedException('rpc not support send file');
        }

        $content = $response->content;

        if (is_string($content)) {
            echo $content;
        } else {
            echo json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return $this;
    }
}