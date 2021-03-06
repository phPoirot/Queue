<?php
namespace Poirot\Queue\Queue;

use MongoDB;

use Poirot\Queue\Exception\exIOError;
use Poirot\Queue\Exception\exReadError;
use Poirot\Queue\Exception\exWriteError;
use Poirot\Queue\Interfaces\iPayload;
use Poirot\Queue\Interfaces\iPayloadQueued;
use Poirot\Queue\Interfaces\iQueueDriver;
use Poirot\Queue\Payload\QueuedPayload;
use Poirot\Storage\Interchange\SerializeInterchange;


/**
 * These Indexes:
 *
 * 	{
 *    "queue": NumberLong(1)
 * }
 * {
 *   "_id": NumberLong(1),
 *   "queue": NumberLong(1)
 * }
 * {
 *   "pop": NumberLong(1)
 *   "queue": NumberLong(1)
 * }
 */
class MongoQueue
    implements iQueueDriver
{
    /** @var MongoDB\Collection */
    protected $collection;

    private static $typeMap = [
        'root' => 'array',
        'document' => 'array',
        'array' => 'array',
    ];

    /** @var SerializeInterchange */
    protected $_c_interchangable;


    /**
     * MongoQueue constructor.
     *
     * @param MongoDB\Collection $collection
     */
    function __construct(MongoDB\Collection $collection)
    {
        $this->collection = $collection;
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
        if ( null === $queue && $payload instanceof iPayloadQueued )
            $queue = $payload->getQueue();




        /** @var QueuedPayload $qPayload */
        $qPayload = $payload;

        $time = ($payload instanceof iPayloadQueued)
            ? $time = $payload->getCreatedTimestamp()
            : time();

        if (! $payload instanceof iPayloadQueued ) {
            $qPayload = new QueuedPayload($payload);

            $uid      = new MongoDB\BSON\ObjectID();
            $qPayload = $qPayload
                ->withUID( $uid )
            ;

        } else {

            $uid = new MongoDB\BSON\ObjectID($qPayload->getUID());
        }

        $qPayload = $qPayload->withQueue( $this->_normalizeQueueName($queue) );


        // .............

        $sPayload = $this->_interchangeable()
            ->makeForward($qPayload);

        try {
            $this->collection->insertOne(
                [
                    '_id'     => $uid,
                    'queue'   => $qPayload->getQueue(),
                    'payload' => new MongoDB\BSON\Binary($sPayload, MongoDB\BSON\Binary::TYPE_GENERIC),
                    'payload_humanize' => $sPayload,
                    'created_timestamp' => $time,
                    'pop'     => false, // not yet popped; against race condition
                ]
            );

        } catch (\Exception $e) {
            throw new exWriteError('Error While Write To Mongo Client.', $e->getCode(), $e);
        }


        return $qPayload;
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
        try {
            $queued = $this->collection->findOneAndUpdate(
                [
                    'queue' => $this->_normalizeQueueName($queue),
                    'pop'   => false,
                ]
                , [
                    '$set' => ['pop' => true]
                ]
                , [
                    // pick last one in the queue
                    'sort'    => [ '_id' => -1 ],
                    // override typeMap option
                    'typeMap' => self::$typeMap,
                ]
            );
        } catch (\Exception $e) {
            throw new exReadError('Error While Write To Mongo Client.', $e->getCode(), $e);
        }

        if (! $queued )
            return null;


        $payload = $this->_interchangeable()
            ->retrieveBackward($queued['payload']);

        $payload = $payload->withQueue($queue)
            ->withUID( (string) $queued['_id'] );

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
            $arg   = $id;
            $id    = $arg->getUID();
            $queue = $arg->getQueue();
        }

        try {
            $this->collection->deleteOne([
                '_id'   => new MongoDB\BSON\ObjectID($id),
                'queue' => $this->_normalizeQueueName($queue),
            ]);
        } catch (\Exception $e) {
            throw new exWriteError('Error While Write To Mongo Client.', $e->getCode(), $e);
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
        try {
            $queued = $this->collection->findOne(
                [
                    '_id'   => new MongoDB\BSON\ObjectID( (string) $id),
                    'queue' => $this->_normalizeQueueName($queue),
                ]
                , [
                    // override typeMap option
                    'typeMap' => self::$typeMap,
                ]
            );
        } catch (\Exception $e) {
            throw new exReadError('Error While Write To Mongo Client.', $e->getCode(), $e);
        }


        if (! $queued )
            return null;


        $payload = $this->_interchangeable()
            ->retrieveBackward($queued['payload']);

        $payload = $payload->withQueue($queue)
            ->withUID( (string) $queued['_id'] );

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
        try {
            $count = $this->collection->count(
                [
                    'queue' => $this->_normalizeQueueName($queue),
                ]
            );
        } catch (\Exception $e) {
            throw new exReadError('Error While Write To Mongo Client.', $e->getCode(), $e);
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
        try {
            $csr = $this->collection->aggregate(
                [
                    [
                        '$group' => [
                            '_id'   => '$queue',
                        ],
                    ],
                ]
                , [
                    // override typeMap option
                    'typeMap' => self::$typeMap,
                ]
            );
        } catch (\Exception $e) {
            throw new exReadError('Error While Write To Mongo Client.', $e->getCode(), $e);
        }

        $list = [];
        foreach ($csr as $item)
            $list[] = $item['_id'];

        return $list;
    }


    // ..

    /**
     * @return SerializeInterchange
     */
    protected function _interchangeable()
    {
        if (! $this->_c_interchangable)
            $this->_c_interchangable = new SerializeInterchange;

        return $this->_c_interchangable;
    }

    /**
     * @param string $queue
     * @return string
     */
    protected function _normalizeQueueName($queue)
    {
        if ($queue === null)
            return $queue = 'general';

        return strtolower( (string) $queue );
    }
}