<?php

namespace Rx\Thruway\Observable;

use Rx\Disposable\EmptyDisposable;
use Rx\Observable;
use Rx\ObserverInterface;
use Thruway\Common\Utils;
use Thruway\WampErrorException;
use Thruway\Message\{
    Message, CallMessage, ResultMessage, ErrorMessage
};

class CallObservable extends Observable
{

    private $uri;
    private $args;
    private $argskw;
    private $options;
    private $messages;
    private $sendMessage;

    function __construct(string $uri, Observable $messages, callable $sendMessage, array $args = null, array $argskw = null, array $options = null)
    {
        $this->uri         = $uri;
        $this->args        = $args;
        $this->argskw      = $argskw;
        $this->options     = (object)$options;
        $this->messages    = $messages;
        $this->sendMessage = $sendMessage;
    }

    public function subscribe(ObserverInterface $observer, $scheduler = null)
    {
        $requestId = Utils::getUniqueId();
        $callMsg   = new CallMessage($requestId, $this->options, $this->uri, $this->args, $this->argskw);

        $resultMsg = $this->messages
            ->filter(function (Message $msg) use ($requestId) {
                return $msg instanceof ResultMessage && $msg->getRequestId() === $requestId;
            });

        //Take until we get a result without progress
        $resultMsg = $resultMsg->takeUntil($resultMsg->filter(function (ResultMessage $msg) {
            return !($msg->getDetails()->progess ?? false);
        }));

        $error = $this->messages
            ->filter(function (Message $msg) use ($requestId) {
                return $msg instanceof ErrorMessage && $msg->getErrorRequestId() === $requestId;
            })
            ->flatMap(function (ErrorMessage $msg) {
                return Observable::error(new WampErrorException($msg->getErrorURI(), $msg->getArguments()));
            })
            ->takeUntil($resultMsg)
            ->take(1);

        try {
            $sm = call_user_func($this->sendMessage, $callMsg);
        } catch (\Exception $e) {
            $observer->onError($e);
            return new EmptyDisposable();
        }

        return $sm
            ->merge($error)
            ->merge($resultMsg)
            ->map(function (ResultMessage $msg) {
                return [$msg->getArguments(), $msg->getArgumentsKw(), $msg->getDetails()];
            })
            ->subscribe($observer, $scheduler);
    }
}