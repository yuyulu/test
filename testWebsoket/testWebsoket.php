<?php

class ImServer
{
    protected $sw_server;
    protected $user_table;


    public function __construct($host = '0.0.0.0', $port = '9501', Array $setting = [])
    {
        $this->sw_server = new swoole_websocket_server($host, $port);
        $default_setting = [
            'worker_num' => 1,
            'task_worker_num' => 4,
            'log_file' => 'im.log',
            'daemonize' => 1,
        ];

        $this->sw_server->set(array_merge($default_setting, $setting));

        $this->sw_server->on('open', [$this, 'onOpen']);
        $this->sw_server->on('close', [$this, 'onClose']);
        $this->sw_server->on('message', [$this, 'onMessage']);
        $this->sw_server->on('task', [$this, 'onTask']);
        $this->sw_server->on('finish', [$this, 'onFinish']);
        $this->sw_server->on('workerstart', [$this, 'onWorkerStart']);
        $this->sw_server->on('request', [$this, 'onRequest']);

        $this->user_table = new swoole_table(65536);
        $this->user_table->column('fd', swoole_table::TYPE_INT, 8);
        $this->user_table->create();

    }

    public function start()
    {
        $this->sw_server->start();
    }

    public function onOpen(swoole_websocket_server $server, $request)
    {
        if (!isset($request->get)) {
            $server->close($request->fd);
            return;
        }
        $get = $request->get;

        $token = isset($get['token']) ? $get['token'] : false;
        if ($token === false || strpos($token, '.' === false)) {
            $server->close($request->fd);
            return;
        }

        list($uid, $_) = explode('.', $token);
        //同一客户只能同时有一个连接
        if ($this->user_table->exist($uid)) {
            $fd = $this->user_table->get($uid, 'fd');
            $server->close($fd);
        }
        $this->user_table->set($uid, ['fd' => $request->fd]);

        $server->task(json_encode([
            'event' => 'getOfflineMessage',
            'uid' => $uid,
            'fd' => $request->fd,
        ]));
    }
    public function onMessage(swoole_websocket_server $server, $frame)
    {
        $data = json_decode($frame->data, true);

        $task_data = [
            'event' => 'saveMessage',
            'data' => $data
        ];
        $task_data = json_encode($task_data);
        $server->task($task_data);

        if ($this->user_table->exist($data['to'])) {
            $target_fd = $this->user_table->get($data['to'], 'fd');
            $msg = [
                'message' => $data['message'],
                'time' => $data['time'],
                'type' => 'text',
                'uid' => $data['from'],
            ];
            $server->push($target_fd, json_encode([
                'event' => 'message',
                'msg' => $msg,
            ]));
        }
        return;
    }


    public function onTask($server, $task_id, $worker_id, $data)
    {
        $task_data = json_decode($data, true);
        $db = new PDO(
            'mysql:host=localhost;dbname=im;charset=utf8mb4;',
            'root',
            'root'
        );
        switch ($task_data['event']) {
            case 'saveMessage':
                $statement = $db->prepare('insert into `chat_message` (`from`, `to`, `time`, `type`, `message`) values (?, ?, ?, ?, ?)');
                $statement->execute([
                    $task_data['data']['from'],
                    $task_data['data']['to'],
                    $task_data['data']['time'],
                    $task_data['data']['type'],
                    base64_encode($task_data['data']['message']),
                ]);
                break;

            case 'getOfflineMessage':
                $uid = (int)$task_data['uid'];
                $fd = $task_data['fd'];

                $statement = $db->prepare('select * from `chat_message` where `to` = ? or `from` = ?');
                $statement->execute([$uid, $uid]);
                $result = $statement->fetchAll(PDO::FETCH_ASSOC);

                foreach ($result as & $v) {
                    if ($v['type'] == 'text') {
                        $v['message'] = base64_decode($v['message']);
                    }
                }
                unset($v);

                $server->push($fd, json_encode([
                    'event' => 'getOfflineMessage',
                    'msgs' => $result,
                ]));
                break;

            default:
                # code...
                break;
        }
    }

    public function onClose($server, $fd, $reactor_id)
    {
        foreach ($this->user_table as $k => $v) {
            if ($fd == $v['fd']) {
                $this->user_table->del($k);
                break;
            }
        }
    }

    public function onFinish($task_id, $data)
    {

    }

    public function onWorkerStart($server, $worker_id)
    {

    }
}

$im_server = new ImServer();
$im_server->start();
