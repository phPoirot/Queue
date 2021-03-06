<?php
namespace Poirot\Queue\Queue;

use Poirot\Queue\Exception\exIOError;
use Poirot\Queue\Interfaces\iPayload;
use Poirot\Queue\Interfaces\iPayloadQueued;
use Poirot\Queue\Interfaces\iQueueDriver;


class AggregateQueue
    implements iQueueDriver
{
    /** @var []iQueueDriver */
    protected $channels_queue  = [];
    protected $channels_weight = [];


    /**
     * MongoQueue constructor.
     *
     * @param iQueueDriver[] $queues
     */
    function __construct(array $queues = null)
    {
        if (null !== $queues)
            $this->setQueues($queues);

    }


    /**
     * Push To Queue
     *
     * @param iPayload $payload Serializable payload
     * @param string   $queue
     *
     * @return iPayloadQueued
     * @throws exIOError
     */
    function push($payload, $queue = null)
    {
        if ($queue === null)
        {
            ## Push To Channels With Max Priority Weight
            #
            $weights = $this->channels_weight;
            while (! empty($weights) )
            {
                $channel = \Poirot\Queue\mathAlias($weights);
                unset($weights[$channel]);

                /** @var iQueueDriver $queue */
                $queue  = $this->channels_queue[$channel];
                if ( $payload = $queue->push($payload, $channel) )
                    break;
            }

        } else {
            if (false === $qd = $this->_getQueueOfChannel($queue) )
                throw new \RuntimeException(sprintf(
                    'Channel (%s) is not present when trying to push payload: (%s).'
                    , $queue, \Poirot\Std\flatten($payload)
                ));


            $payload = $qd->push($payload, $queue);
        }

        return $payload;
    }

    /**
     * Pop From Queue
     *
     * note: when you pop a message from queue you have to
     *       release it when worker done with it.
     *
     *
     * @param string $queue
     *
     * @return iPayloadQueued|null
     * @throws exIOError
     */
    function pop($queue = null)
    {
        if ($queue === null)
        {
            $payload = null;

            ## Pop From Channels With Max Priority Weight
            #
            $weights = $this->channels_weight;
            while (! empty($weights) )
            {
                $channel = \Poirot\Queue\mathAlias($weights);
                unset($weights[$channel]);

                /** @var iQueueDriver $queue */
                $queue  = $this->channels_queue[$channel];
                if ($payload = $queue->pop($channel))
                    break;
                /*else
                    echo "Channel ($channel) Was Empty";*/
            }

        } else {
            if (false === $qd = $this->_getQueueOfChannel($queue) )
                throw new \RuntimeException(sprintf(
                    'Channel (%s) is not present when trying to pop'
                    , $queue
                ));

            $payload = $qd->pop($queue);
        }

        return $payload;
    }

    /**
     * Release an Specific From Queue By Removing It
     *
     * @param iPayloadQueued|string $id
     * @param null|string           $queue
     *
     * @return void
     * @throws exIOError
     */
    function release($id, $queue = null)
    {
        if ( $id instanceof iPayloadQueued ) {
            $arg = $id;
            $id    = $arg->getUID();
            $queue = $arg->getQueue();
        }

        if ($queue === null)
        {
            /** @var iQueueDriver $queue */
            foreach ($this->channels_queue as $channel => $queue ) {
                if ( $queue->findByID($id, $channel) )
                    $queue->release($id, $queue);
            }

        } else {
            if (false === $qd = $this->_getQueueOfChannel($queue) )
                throw new \RuntimeException(sprintf(
                    'Channel (%s) is not present when trying to release: (%s)'
                    , $queue ,\Poirot\Std\flatten($id)
                ));


            $qd->release($id, $queue);
        }
    }

    /**
     * Find Queued Payload By Given ID
     *
     * @param string $id
     * @param string $queue
     *
     * @return iPayloadQueued|null
     * @throws exIOError
     */
    function findByID($id, $queue = null)
    {
        if ($queue === null)
        {
            $payload = null;

            /** @var iQueueDriver $queue */
            foreach ($this->channels_queue as $channel => $queue ) {
                if ( $payload = $queue->findByID($id, $channel) )
                    break;
            }

        } else {
            if (false === $qd = $this->_getQueueOfChannel($queue) )
                throw new \RuntimeException(sprintf(
                    'Channel (%s) is not present when trying to find: (%s).'
                    , $queue ,\Poirot\Std\flatten($id)
                ));


            $payload = $qd->findByID($id, $queue);
        }

        return $payload;
    }

    /**
     * Get Queue Size
     *
     * @param string $queue
     *
     * @return int
     * @throws exIOError
     */
    function size($queue = null)
    {
        if ($queue === null) {

            $count = 0;

            /** @var iQueueDriver $queue */
            foreach ($this->channels_queue as $channel => $queue )
                $count += $queue->size($channel);

        } else {
            if (false === $qd = $this->_getQueueOfChannel($queue) )
                throw new \RuntimeException(sprintf(
                    'Channel (%s) is not present when trying to get size.'
                    , $queue
                ));

            $count = $qd->size($queue);
        }

        return $count;
    }

    /**
     * Get Queues List
     *
     * @return string[]
     * @throws exIOError
     */
    function listQueues()
    {
        return array_keys($this->channels_queue);
    }


    // Options:

    /**
     * Set Queues
     *
     * [
     *   'channel_name' => iQueueDriver,
     *   // or
     *   'channel_name' => [iQueueDriver, $weight]
     * ]
     *
     * @param iQueueDriver[] $channels_queue
     *
     * @return $this
     * @throws \Exception
     */
    function setQueues(array $channels_queue)
    {
        foreach ($channels_queue as $channel => $queue ) {
            if (! is_array($queue) )
                $queue = [$queue];

            $q = array_shift($queue);
            if ( null === $w = array_shift($queue) )
                $w = 1;

            $this->addQueue($channel, $q, $w);
        }

        return $this;
    }

    /**
     * Add Queue For Specified Channel
     *
     * @param string       $channel
     * @param iQueueDriver $queue
     * @param int          $weight
     *
     * @return $this
     * @throws \Exception
     */
    function addQueue($channel, $queue, $weight = 1)
    {
        $orig    = $channel;
        $channel = $this->_normalizeQueueName($channel);


        if (! $queue instanceof iQueueDriver)
            throw new \Exception(sprintf(
                'Queue must be instance of iQueueDriver; given: (%s).'
                , \Poirot\Std\flatten($queue)
            ));

        if ( isset($this->channels_queue[$channel]) )
            throw new \RuntimeException(sprintf(
                'Channel (%s) is currently filled with (%s) and is not empty.'
                , $orig , get_class( $this->channels_queue[$channel] )
            ));


        $this->channels_queue[$channel]  = $queue;
        $this->channels_weight[$channel] = $weight;
        return $this;
    }


    // ..

    /**
     * @param string $channel
     * @return iQueueDriver
     */
    protected function _getQueueOfChannel($channel)
    {
        $channel = $this->_normalizeQueueName($channel);
        return $this->channels_queue[$channel] ?? false;
    }

    /**
     * @param string $queue
     * @return string
     */
    protected function _normalizeQueueName($queue)
    {
        return strtolower( (string) $queue );
    }
}