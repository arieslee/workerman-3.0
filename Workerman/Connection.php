<?php
namespace Workerman;
use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\BaseEvent;

class Connection
{
    const READ_BUFFER_SIZE = 8192;

    const STATUS_NULL = 0;

    const STATUS_CONNECTING = 1;

    const STATUS_CLOSING = 8;

    public static $globalEvent = null;

    public $event = null;

    public $socket = null;
    
    public $onConnect = null;

    public $onMessage = null;

    public $onClose = null;

    protected $_sendBuffer = '';

    protected $_status = self::STATUS_NULL;
    
    protected $_remoteIp = '';
    
    protected $_remotePort = 0;

    public function __construct($socket)
    {
        $this->socket = $socket;
    }
    
    public function send($send_buffer)
    {
        if($this->_sendBuffer === '')
        {
            $len = fwrite($this->socket, $send_buffer);
            if($len === strlen($send_buffer))
            {
                return true;
            }
            elseif($len > 0)
            {
                $this->_sendBuffer = substr($send_buffer, $len);
            }
            else
            {
                if(feof($this->socket))
                {
                    $this->shutdown();
                    return;
                }
                $this->_sendBuffer = $send_buffer;
            }
        }
        if($this->_sendBuffer !== '')
        {
            $this->_sendBuffer .= $send_buffer;
            $this->event->add($this->socket, BaseEvent::EV_WRITE, array($this, 'baseWrite'));
        }
    }

    public function join($event = null)
    {
        if($event)
        {
            $this->event = $event;
        }
        else
        {
            $this->event = self::$globalEvent;
        }
        $this->event->add($this->socket, BaseEvent::EV_READ, array($this, 'baseRead'));
    }
    
    public function getRemoteIp()
    {
        if(!$this->_remoteIp)
        {
            if($address = stream_socket_get_name($this->socket, false))
            {
                list($this->_remoteIp, $this->_remotePort) = explode(':', $address, 2);
            }
        }
        return $this->_remoteIp;
    }
    
    public function getRemotePort()
    {
        if(!$this->_remotePort)
        {
            if($address = stream_socket_get_name($this->socket, false))
            {
                list($this->_remoteIp, $this->_remotePort) = explode(':', $address, 2);
            }
        }
        return $this->_remotePort;
    }

    public function baseRead($socket)
    {
       $recv_buffer = '';
       while($buffer = fread($socket, self::READ_BUFFER_SIZE))
       {
          $recv_buffer .= $buffer; 
       }
       
       if(feof($socket))
       {
           $this->shutdown();
           return;
       }
       if($recv_buffer !== '' && $this->onMessage)
       {
           $func = $this->onMessage;
           $func($this, $recv_buffer);
       }
    }

    public function baseWrite()
    {
        $len = fwrite($this->socket, $this->_sendBuffer);
        if($len == strlen($this->_sendBuffer))
        {
            $this->event->del($this->socket, BaseEvent::EV_WRITE);
            $this->_sendBuffer = '';
            if($this->_status == self::STATUS_CLOSING)
            {
                $this->shutdown();
            }
            return true;
        }
        if($len > 0)
        {
           $this->_sendBuffer = substr($this->_sendBuffer, $len);
        }
        else
        {
           if(feof($this->socket))
           {
               $this->shutdown();
           }
        }
    }

    public function close()
    {
        $this->_status = self::STATUS_CLOSING;
        if($this->_sendBuffer === '')
        {
           $this->shutdown();
        }
    }

    public function shutdown()
    {
       if($this->onClose)
       {
           $func = $this->onClose;
           $func($this);
       }
       $this->event->del($this->socket, BaseEvent::EV_READ);
       $this->event->del($this->socket, BaseEvent::EV_WRITE);
       fclose($this->socket);
    }
}
