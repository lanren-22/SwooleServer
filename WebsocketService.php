<?php
/**
 * swoole websocket 服务
 */
class WebsocketService{
    private $ws;
    public function __construct(){
        $this->ws = new swoole_websocket_server("0.0.0.0", 9501);
        // 配置信息
        $this->ws->set(array(
            'worker_num' => 4,
            'daemonize' => false, //是否守护进程
            'max_request' => 5000,
            'dispatch_mode' => 1, //轮循模式
            'max_conn' => 5000, //最大允许维持多少个TCP连接数
            //每300秒侦测一次心跳，一个TCP连接如果在600秒内未向服务器端发送数据，将会被切断。
            'heartbeat_check_interval' => 300, //心跳检测
            'heartbeat_idle_time' => 600,
            'log_file' => '/alidata/log/swoole.log'
        ));

        $this->ws->on('open', array($this, 'onOpen'));
        $this->ws->on('message', array($this, 'onMessage'));
        $this->ws->on('request', array($this, 'onRequest'));
        // $this->ws->on('receive', array($this, 'onReceive'));
        // $this->ws->on('task', array($this, 'onTask'));
        // $this->ws->on('finish', array($this, 'onFinish'));
        $this->ws->on('close', array($this, 'onClose'));

        $this->ws->start();
    }

    /**
     * [onOpen 握手成功回调]
     * @Date   2019-10-09
     * @param  [type]     $ws      [description]
     * @param  [type]     $request [description]
     * @return [type]              [description]
     */
    public function onOpen($ws, $request){
        $this->ws->push($request->fd, "{$request->fd},hello, welcome\n");
    }

    /**
     * [onMessage 消息处理回调]
     * @Date   2019-10-09
     * @param  [type]     $ws    [description]
     * @param  [type]     $frame [description]
     * @return [type]            [description]
     */
    public function onMessage($ws, $frame){
        echo "onMessage: {$frame->data}\n";
        $data = json_decode($frame->data,true);
        if($data['flag'] == 'init'){//初始化
            // 记录用户对应的socket_id
            $bindRes = $this->bindFd($data['userType'],$data['fid'],$frame->fd);
            echo "绑定结果：".$bindRes;
        }else if($data['flag'] == 'text'){//非初始化的信息发送，一对一聊天，根据每个用户对应的fd发给特定用户
            $type = 'text';
            $this->sendMsg($data,$type);
        }else if($data['flag'] == 'all'){//广播给所有的在线用户
            $pushData['type'] = 'text';
            $pushData['content'] = $data['content'];
            $pushData = json_encode($pushData);
            foreach($this->ws->connections as $fd){
                $this->ws->push($fd , $pushData);
            }
        }
    }

    /**
     * [sendMsg 消息推送]
     * @Date   2019-10-11
     * @param  [type]     $data [description]
     * @return [type]           [description]
     */
    private function sendMsg($data){
        //消息要发给谁
        $tofd = intval($data['tid']);
        //判断是否在线
        $fds = [];
        foreach($this->ws->connections as $fd){
            // 需要先判断是否是正确的websocket连接，否则有可能会push失败
            if($this->ws->isEstablished($fd)){
                array_push($fds, $fd);
            }else{
                echo('时间：【'.date('Y-m-d H:i:s').'】;websocket 连接不正确 fd无效。fd为：'.$fd."\n");
            }
        }
        if(in_array($tofd,$fds)){
            $tmp['content']  = $data['content']; //消息内容
            $returnData = json_encode($tmp);
            $this->ws->push($tofd , $returnData);
            // 记录历史记录
            $status = 1;
        }else{
            echo "sendMsg: 当前用户client-{$tofd}不在线\n";
            // 记录离线消息
            $status = 0;
        }
        // $this->addChatRocord($data,$status,$type);
    }

    /**
     * [onClose 用户关闭连接回调]
     * @Date   2019-10-09
     * @param  [type]     $ws [description]
     * @param  [type]     $fd [description]
     * @return [type]         [description]
     */
    public function onClose($ws, $fd){
        echo "client-{$fd} is closed\n";
    }

    /**
     * [onRequest 监听WebSocket主动推送消息事件]
     * @Date   2019-10-11
     * @param  [type]     $request  [description]
     * @param  [type]     $response [description]
     * @return [type]               [description]
     * http://test.demo.com:9501/?type=url&tid=1&content=22
     * http://test.demo.com:9501/?type=url&tid=1&content=22&task=1
     */
    public function onRequest($request, $response){
        // 获取值
        $sendData = $request->get;
        print_r($sendData);
        if(empty($sendData['type'])){
            return false;
        }

        // if(isset($sendData['task']) && $sendData['task']){
        //     // 将任务分发给异步任务处理
        //     $task_id = $this->ws->task($sendData);
        //     echo "Dispath AsyncTask In onRequest: id=$task_id\n";
        //     return false;
        // }

        $type = 'url';
        if(!empty($sendData['content'])){
            // 推送消息给指定用户
            $result = json_encode($sendData);
            echo "onRequest: {$result}\n";
            $this->sendMsg($sendData,$type);
        }
    }

    /**
     * [onReceive 投递异步任务]
     * @param  [type] $ws      [description]
     * @param  [type] $fd      [description]
     * @param  [type] $from_id [description]
     * @param  [type] $data    [description]
     * @return [type]          [description]
     */
    // public function onReceive($ws, $fd, $from_id, $data){
    //     print_r($data);
    //     $task_id = $this->ws->task($data);
    //     echo "Dispath AsyncTask: id=$task_id\n";
    // }

    /**
     * [onTask 处理异步任务]
     * @param  [type] $ws      [description]
     * @param  [type] $task_id [description]
     * @param  [type] $from_id [description]
     * @param  [type] $data    [description]
     * @return [type]          [description]
     */
    // public function onTask($ws, $task_id, $from_id, $data){
    //     print_r($data);
    //     if(isset($data['task']) && $data['task']){
    //         sleep(5);
    //         $type = 'text';
    //         $this->sendMsg($data,$type);
    //     }
    //     $this->ws->finish("AsyncTask OK");
    //     echo "AsyncTask OK[id=$task_id]".PHP_EOL;
    // }

    /**
     * [onFinish 异步任务完成回调]
     * @param  [type] $ws      [description]
     * @param  [type] $task_id [description]
     * @param  [type] $data    [description]
     * @return [type]          [description]
     */
    // public function onFinish($ws, $task_id, $data){
    //     echo "AsyncTask[$task_id] Finish: $data".PHP_EOL;
    // }

    /**
     * [bindFd 用户绑定socket_id]
     * @Date   2019-10-09
     * @param  [type]     $userType [用户类型：client=消费者端 business=商户端]
     * @param  [type]     $uid  [用户ID]
     * @param  [type]     $fd   [socket_id]
     * @return [type]           [description]
     */
    private function bindFd($userType,$uid,$fd){
        if(!in_array($userType, ['client','business'])){
            return false;
        }
        $user_type = ['client'=>'1','business'=>'2'];

        // 连接数据库
        $mysql = new LanSql('localhost','root','','lanren_blog');
        $bindData = $mysql->pdo_get('lan_bind_fd',['user_type'=>$user_type[$userType],'user_id'=>$uid],'id,fd');
        if(!empty($bindData)){
            $res = $mysql->pdo_update('lan_bind_fd',['fd'=>$fd,'update_time'=>time()],['id'=>$bindData['id']]);
        }else{
            $res = $mysql->pdo_insert('lan_bind_fd',['user_type'=>$user_type[$userType],'user_id'=>$uid,'fd'=>$fd,'create_time'=>time()]);
        }
        return $res;
    }

    /**
     * [getBindFd 获取当前用户的socket_id]
     * @Date   2019-10-09
     * @param  [type]     $userType [用户类型：client=消费者端 business=商户端]
     * @param  [type]     $uid  [用户ID]
     * @return [type]           [description]
     */
    private function getBindFd($userType,$uid){
        if(!in_array($utype, ['client','business'])){
            return false;
        }
        $user_type = ['client'=>'1','business'=>'2'];

        // 连接数据库
        $mysql = new LanSql('localhost','root','','lanren_blog');
        $bindData = $mysql->pdo_get('lan_bind_fd',['user_type'=>$user_type[$userType],'user_id'=>$uid],'id,fd');
        return $bindData['fd'];
    }
    
    /**
     * [addChatRocord 添加聊天记录]
     * @Date  2019-10-10
     * @param [type]     $param  [记录参数]
     * @param [type]     $status [是否已读 0=未读 1=已读]
     */
    // private function addChatRocord($param,$status,$type){
    //     // 历史记录数据
    //     $record['type'] = $type;
    //     $record['userType'] = $param['userType'];
    //     $record['fid'] = $param['fid'];
    //     $record['tid'] = $param['tid'];
    //     $record['goodsId'] = $param['goodsId'];
    //     $record['content'] = $param['content'];
    //     $record['status'] = $status;
    //     $res = AdminChatRecordFunction::addChatRocord($record);
    //     return $res;
    // }

    /**
     * [httpGet GET]
     * @param  [type]  $url     [description]
     * @param  integer $timeOut [description]
     * @return [type]           [description]
     */
    private function httpGet($url, $timeOut = 10) {
        if (empty ( $timeOut )) {
            $timeOut = 10;
        }
        $tryI = 0;
        while ( $tryI < 5 ) {
            try {
                $curl = curl_init ();
                curl_setopt ( $curl, CURLOPT_RETURNTRANSFER, true );
                curl_setopt ( $curl, CURLOPT_TIMEOUT, $timeOut );
                curl_setopt ( $curl, CURLOPT_URL, $url );
                
                $res = curl_exec ( $curl );
                curl_close ( $curl );
                
                return $res;
            } catch ( Exception $e ) {
                error_log ( 'HttpUtil httpGet:' . $url . ' error' . $e->getMessage () );
            }
            $tryI ++;
            usleep ( 100000 * $tryI * $tryI );
        }
        return null;
    }
    
    /**
     * [httpPost POST]
     * @param  [type]  $url     [description]
     * @param  [type]  $data    [description]
     * @param  integer $timeOut [description]
     * @return [type]           [description]
     */
    private function httpPost($url, $data = null, $timeOut = 10) {
        if (empty ( $timeOut )) {
            $timeOut = 10;
        }
        $tryI = 0;
        while ( $tryI < 5 ) {
            try {
                $curl = curl_init ();
                curl_setopt ( $curl, CURLOPT_URL, $url );
                curl_setopt ( $curl, CURLOPT_SSL_VERIFYPEER, FALSE );
                curl_setopt ( $curl, CURLOPT_SSL_VERIFYPEER, FALSE );
                if (! empty ( $data )) {
                    curl_setopt ( $curl, CURLOPT_POST, 1 );
                    curl_setopt ( $curl, CURLOPT_POSTFIELDS, $data );
                }
                curl_setopt ( $curl, CURLOPT_RETURNTRANSFER, 1 );
                curl_setopt ( $curl, CURLOPT_TIMEOUT, $timeOut );
                $output = curl_exec ( $curl );
                curl_close ( $curl );
                return $output;
            } catch ( Exception $e ) {
                error_log ( 'HttpUtil httpPost:' . $url . ' error' . $e->getMessage () );
            }
            $tryI ++;
        }
        return null;
    }
}

// 启动服务器
$server = new WebsocketService();

class LanSql{
    private $host;
    private $user;
    private $password;
    private $charset;
    private $database;
    
    /**
     * [__construct 数据库连接对象]
     * @param string $host     [host地址]
     * @param string $user     [数据库账号]
     * @param string $password [数据库密码]
     * @param string $database [数据库名称]
     * @param string $charset  [格式]
     */
    function __construct($host = 'localhost', $user = 'root', $password = 'root', $database = 'lanren_blog', $charset = 'utf8')
    {
        $link = mysqli_connect($host, $user, $password) or die('数据库连接失败<br />ERROR ' . mysqli_connect_errno() . ':' . mysqli_connect_error());
        $char = mysqli_set_charset($link, $charset) or die('charset设置错误，请输入正确的字符集名称<br />ERROR ' . mysqli_errno($link) . ':' . mysqli_error($link));
        $db = mysqli_select_db($link, $database) or die('未找到数据库，请输入正确的数据库名称<br />ERROR ' . mysqli_errno($link) . ':' . mysqli_error($link));
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;
        $this->charset = $charset;
        $this->database = $database;
        mysqli_close($link);
    }

    /**
     * [connect 连接数据库]
     * @param  [type] $host     [host地址]
     * @param  [type] $user     [数据库账号]
     * @param  [type] $password [数据库密码]
     * @param  [type] $database [数据库名称]
     * @param  [type] $charset  [格式]
     * @return [type]           [description]
     */
    private function connect($host, $user, $password, $database, $charset)
    {
        $link = mysqli_connect($this->host, $this->user, $this->password);
        mysqli_set_charset($link, $this->charset);
        mysqli_select_db($link, $this->database);
        return $link;
    }

    /**
     * [pdo_insert 增加数据]
     * @param  [type] $table [表名]
     * @param  [type] $data  [添加参数]
     * @return [type]        [description]
     */
    public function pdo_insert($table,$data)
    {
        $link = $this->connect($this->host, $this->user, $this->password, $this->database, $this->charset);
        $keys = join(',', array_keys($data));
        $vals = "'" . join("','", array_values($data)) . "'";
        $query = "INSERT INTO {$table}({$keys}) VALUES({$vals})";
        $result = mysqli_query($link, $query) or die('插入数据出错，请检查！<br />ERROR ' . mysqli_errno($link) . ':' . mysqli_error($link));
        if ($result) {
            $id = mysqli_insert_id($link);
        } else {
            $id = false;
        }
        mysqli_close($link);
        return $id;
    }

    /**
     * [pdo_delete 删除数据]
     * @param  [type] $table  [表名]
     * @param  array  $_where [搜索条件]
     * @return [type]         [description]
     */
    public function pdo_delete($table, $_where = [])
    {
        $link = $this->connect($this->host, $this->user, $this->password, $this->database, $this->charset);
        $where = $this->strWhere($_where);
        $query = "DELETE FROM {$table} WHERE {$where}";
        $result = mysqli_query($link, $query) or die('删除数据出错，请检查！<br />ERROR ' . mysqli_errno($link) . ':' . mysqli_error($link));
        if ($result) {
            $row = mysqli_affected_rows($link);
        } else {
            $row = false;
        }
        mysqli_close($link);
        return $row;
    }

    /**
     * [pdo_update 修改数据]
     * @param  [type] $table  [表名]
     * @param  [type] $data   [更新数据]
     * @param  array  $_where [条件]
     * @return [type]         [description]
     */
    public function pdo_update($table,$data,$_where = [])
    {
        $link = $this->connect($this->host, $this->user, $this->password, $this->database, $this->charset);
        $set = '';
        foreach ($data as $key => $val) {
            $set .= "{$key}='{$val}',";
        }
        $set = trim($set, ',');
        $where = $this->strWhere($_where);
        $query = "UPDATE {$table} SET {$set} WHERE {$where}";
        $result = mysqli_query($link, $query) or die('数据修改错误，请检查！<br />ERROR ' . mysqli_errno($link) . ':' . mysqli_error($link));
        if ($result) {
            $row = mysqli_affected_rows($link);
        } else {
            $row = false;
        }
        mysqli_close($link);
        return $row;
    }

    /**
     * [pdo_get 获取一条数据]
     * @param  [type] $table       [表名]
     * @param  [type] $_where      [查询条件]
     * @param  string $field       [搜索参数]
     * @param  [type] $result_type [description]
     * @return [type]              [description]
     */
    public function pdo_get($table,$_where,$field = '*',$result_type = MYSQLI_ASSOC)
    {
        $link = $this->connect($this->host, $this->user, $this->password, $this->database, $this->charset);
        $where = $this->strWhere($_where);
        $query = "SELECT {$field} FROM {$table} WHERE {$where} LIMIT 1";
        $result = mysqli_query($link, $query) or die('查询语句错误，请检查！<br />ERROR ' . mysqli_errno($link) . ':' . mysqli_error($link));
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_array($result, $result_type);
        } else {
            $row = false;
        }
        mysqli_free_result($result);
        mysqli_close($link);
        return $row;
    }

    /**
     * [pdo_selectall 查询多条数据]
     * @param  [type] $table       [数据表]
     * @param  [type] $_where      [搜索条件]
     * @param  string $field       [搜索参数]
     * @param  [type] $result_type [description]
     * @return [type]              [description]
     */
    public function pdo_selectall($table,$_where,$field = '*',$result_type = MYSQLI_ASSOC)
    {
        $link = $this->connect($this->host, $this->user, $this->password, $this->database, $this->charset);
        $where = $this->strWhere($_where);
        $query = "SELECT {$field} FROM {$table} WHERE {$where}";
        $result = mysqli_query($link, $query) or die('查询语句错误，请检查！<br />ERROR ' . mysqli_errno($link) . ':' . mysqli_error($link));
        if ($result && mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_array($result, $result_type)) {
                $rows[] = $row;
            }
        } else {
            $rows = false;
        }
        mysqli_free_result($result);
        mysqli_close($link);
        return $rows;
    }

    /**
     * [get_total_rows 获取表中记录数]
     * @param  [type] $table [表名]
     * @return [type]        [description]
     */
    public function get_total_rows($table)
    {
        $link = $this->connect($this->host, $this->user, $this->password, $this->database, $this->charset);
        $query = "SELECT COUNT(*) AS totalRows FROM {$table}";
        $result = mysqli_query($link, $query);
        if ($result && mysqli_num_rows($result) == 1) {
            $row = mysqli_fetch_assoc($result);
        } else {
            $row['totalRows'] = false;
        }
        mysqli_close($link);
        return $row['totalRows'];
    }

    /**
     * [pdo_query 原生SQL查询]
     * @param  [type] $query       [SQL]
     * @param  [type] $result_type [description]
     * @return [type]              [description]
     */
    public function pdo_query($query,$result_type = MYSQLI_ASSOC)
    {
        $link = $this->connect($this->host, $this->user, $this->password, $this->database, $this->charset);
        $result = mysqli_query($link, $query) or die('查询语句错误，请检查！<br />ERROR ' . mysqli_errno($link) . ':' . mysqli_error($link));
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_array($result, $result_type);
        } else {
            $row = false;
        }
        mysqli_free_result($result);
        mysqli_close($link);
        return $row;
    }

    /**
     * [strWhere 拼接搜索参数]
     * @param  [type] $where  [参数数组]
     * @return [type]         [description]
     */
    private function strWhere($where){
        $keys = array_keys($where);
        $return = [];
        for($i = 0; $i < count($keys); $i++){
            $return[] = ' `'.$keys[$i].'` = '.'\''.$where[$keys[$i]].'\' ';
        }
        return join(' AND ', $return);
    }
}