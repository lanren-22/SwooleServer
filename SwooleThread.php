<?php
/**
 * swoole 的异步线程任务
 */

// 引入邮件类
require_once "vendor/phpmailer/phpmailer/src/PHPMailer.php";
require_once "vendor/phpmailer/phpmailer/src/Exception.php";
require_once "vendor/phpmailer/phpmailer/src/SMTP.php";
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class SwooleThread
{
	private $serv;
	function __construct(){
		$this->serv = new Swoole\Server("127.0.0.1", 9501);

		$this->serv->set([
			'task_worker_num' => 4, //设置异步任务的工作进程数量
			'daemonize' => true, //是否守护进程
			'log_file' => '/alidata/log/swoole.log'
		]);

		// 绑定任务方法
		$this->serv->on('receive', array($this, 'onReceive'));
		$this->serv->on('task', array($this, 'onTask'));
		$this->serv->on('finish', array($this, 'onFinish'));
		$this->serv->on('request', array($this, 'onRequest'));

		// 启动任务
		$this->serv->start();
	}

	/**
	 * 此回调函数在worker进程中执行
	 * @Author   Baker
	 * @DateTime 2020-03-05
	 * @return   [type]     [description]
	 */
	public function onReceive($serv, $fd, $from_id, $data){
		//投递异步任务
	    $task_id = $this->serv->task($data);
	    echo "Dispath AsyncTask: id=$task_id\n";
	}

	/**
	 * 监听主动推送异步任务【http推送】
	 * @Author   Baker
	 * @DateTime 2020-03-05
	 * @param    [type]     $request  [请求参数]
	 * @param    [type]     $response [回应]
	 * @return   [type]               [description]
	 * 例如：http://test.demo.com:9502/?type=url&tid=1&content=22&task=1
	 */
    public function onRequest($request, $response){
        // 获取值
        $sendData = $request->get;
        print_r($sendData);
        // 获取参数投递到 TaskWorker 进程池中
        $task_id = $this->serv->task($sendData);
        echo "Dispath AsyncTask In onRequest: id=$task_id\n";
    }

	/**
	 * 处理异步任务(此回调函数在task进程中执行)
	 * @Author   Baker
	 * @DateTime 2020-03-05
	 * @param    [type]     $serv    [description]
	 * @param    [type]     $task_id [description]
	 * @param    [type]     $from_id [description]
	 * @param    [type]     $data    [description]
	 * @return   [type]              [description]
	 */
	public function onTask($serv, $task_id, $from_id, $data){
		echo "任务参数data:".$data;
		$data = json_decode($data,true);
		switch ($data['task_type']) {
			case 'SEND_MAIL':
				$this->mailToGuys($data);
				break;
			case 'HANDLE_LARGE_DATA':
				break;
			
			default:
				break;
		}
	    //返回任务执行的结果
	    $this->serv->finish("AsyncTask[$task_id] OK");
	}

	/**
	 * 处理异步任务的结果(此回调函数在worker进程中执行)
	 * @Author   Baker
	 * @DateTime 2020-03-05
	 * @param    [type]     $serv    [description]
	 * @param    [type]     $task_id [description]
	 * @param    [type]     $data    [description]
	 * @return   [type]              [description]
	 */
	public function onFinish($serv, $task_id, $data){
		echo "AsyncTask[$task_id] Finish: $data".PHP_EOL;
	}

	/**
	 * 发送邮件
	 * @Author   Baker
	 * @DateTime 2020-03-05
	 * @param    [type]     $data [邮件参数]
	 * @return   [type]           [description]
	 */
	private function mailToGuys($data){
		if(empty($data) || empty($data['email'])){
			return false;
		}
		//实例化
	    $phpMailerObj = new PHPMailer();
	    //启用SMTP
	    $phpMailerObj->IsSMTP();
	    //设置是否输入调试信息
	    $phpMailerObj->SMTPDebug = 0;
	    //smtp服务器的名称（这里以QQ邮箱为例）
	    $phpMailerObj->Host = 'smtp.yeah.net';
	    //启用smtp认证
	    $phpMailerObj->SMTPAuth = true;
	    //465端口需要ssl加密
	    $phpMailerObj->SMTPSecure = "ssl";
	    //你的邮箱名
	    $phpMailerObj->Username = 'lanphp@yeah.net';
	    //邮箱密码
	    $phpMailerObj->Password = 'lijian22';
	    //发件人地址（也就是你的邮箱地址）
	    $phpMailerObj->From = 'lanphp@yeah.net';
	    //发件人姓名
	    $phpMailerObj->FromName = 'LANPHP';
	    $phpMailerObj->AddAddress($data['email'],"尊敬的客户");
	    //设置每行字符长度
	    $phpMailerObj->WordWrap = 50;
	    $phpMailerObj->Port = 465;
	    //是否HTML格式邮件
	    $phpMailerObj->IsHTML(true);
	    //设置邮件编码
	    $phpMailerObj->CharSet= 'utf-8';
	    //邮件主题
	    $phpMailerObj->Subject = $data['title'];
	    //邮件内容
	    $phpMailerObj->Body = $data['content'];
	    //邮件正文不支持HTML的备用显示
	    $phpMailerObj->AltBody = "这是一个纯文本的身体在非营利的HTML电子邮件客户端";
	    //添加附件
	    if(isset($data['attachment'])){
	      $phpMailerObj->AddAttachment($data['attachment']);
	    }
	    //发送邮件
	    if(!$phpMailerObj->send()){
	        return false;
	        // echo $phpMailerObj->ErrorInfo;
	    }else{
	        return true;
	    }
	}
}

// 启动线程服务
$server = new SwooleThread();