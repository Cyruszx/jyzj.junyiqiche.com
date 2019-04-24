<?php

namespace app\index\controller;

use app\common\controller\Frontend;
use think\Controller;
use think\Cache;
use think\Db;
use think\Loader;
use think\Exception;
use think\Config;
use think\Session;


class Driver extends Frontend
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    protected $layout = '';


    public function _initialize()
    {
        parent::_initialize();

    }
    public $appkey = 'd91da2fdf9834922d28a10565afef31a';


    public function index()
    {
        
        $userinfo = $this->selUserInfo(); 
       
        $day_7 = intval($userinfo['query_time']) +intval((7*86400)); 
        
        $uid = Session::get('MEMBER');
        // pr($uid);
        // die;
        
        //判断首先时间字段都不为空
        if(!empty($userinfo['query_time'])){ 
            //计算第一次时间加上7天 
            //如果登录进来当前时间大于数据表里的时间
            if(time()>$day_7){  
               
                //重置次数
                Db::name('user')->where('openid',$uid['openid'])->setField('query_number',2);
            } 
            else{

            }
            //扔出7天后的时间
           
        } 
        
        if($userinfo['query_number']==0){ 
            $this->assign('time7',date('Y-m-d H:i:s',$day_7));
            
        
        }

        //违章信息
        $order_id = Db::name('order')->where(['wx_public_user_id' => $uid['id']])->find()['id'];
        $detail = Db::name('order_details')->where(['order_id' => $order_id])->find()['violation_details'];
  
        //用户头像
        $this->assign([
            'userinfo' => $uid,
            'detail' => json_decode($detail, true)
        ]);
        //用户的查询次数
        $this->assign('user_query_num', $userinfo['query_number']);
        //记住输入信息 ,status 为1 assign出去
        if($this->userInput()['status']==1){
            $this->assign('userInput', $this->userInput());
            
        }
        

        // pr(session('MEMBER'));
        return $this->view->fetch();
    }
    public function ocrView()
    {

        if (request()->isPost()) {
            $userinfo = $this->selUserInfo(); 
            
            //判断查询次数是否为0
            if($this->selUserInfo()['query_number']==0){
                return json(array('state' => 4, 'errmsg' => '当前可查询次数为0,请于'.date('Y-m-d H:i:s',intval($userinfo['query_time'])+intval((7*86400))).'后再查询' ,'data'=>''));
                
            }
            // 获取表单上传文件 例如上传了001.jpg
            $file = request()->file('file');        
            // 移动到框架应用根目录/public/uploads/ 目录下
            $info = $file->move(ROOT_PATH . 'public' . DS . 'uploads');
            if ($info) { 
            // 输出 20160820/42a79759f284b767dfcb2a0197904287.jpg
            $path = $info->getSaveName();
            // 成功上传后 返回上传信息
            // return json(array('info'=>'https://apply.aicheyide.com/uploads'.DS.$path));
            $data = array(
                'api_key' => '6YWpf8Xx8g1Ll2F5w8bNOpNkmOby1Sdh',
                'api_secret' => 'BV_r5bgSN3DY9SELbKpmVUZ52hI-GCPp',
                'image_url' => 'https://driver.junyiqiche.com' . DS . 'uploads' . DS . $path
            ); 
            // return  $res->driving('https://apply.aicheyide.com' .DS. 'uploads'.DS.$path);
            //旷视ocr接口，提取行驶证信息    
            $res = self::curl_post_contents("https://api-cn.faceplusplus.com/cardpp/v1/ocrvehiclelicense", $data);
            if (empty(json_decode($res, true)['cards'])) {
                return json(array('state' => 1, 'errmsg' => '请上传合法清晰的行驶证 “正页” 图片','data'=>''));
            } else {
                //判断车牌是否是公司的车
                $res_plate_no = Db::name('order_details')->where('licensenumber',json_decode($res, true)['cards'][0]['plate_no'])->find();
                if(empty($res_plate_no)){
                    //如果是空的，那么就不是公司的车辆
                return json(array('state' => 3, 'errmsg' => '该车辆为非君忆公司车辆！如有疑问请联系管理员@包','data'=>''));
                
                }
                else{
                    return json(array('state' => 2, 'errmsg' => '识别成功','data'=>json_decode($res, true)['cards']));
                    
                }

                


            }

    // return posts("https://api-cn.faceplusplus.com/cardpp/v1/ocrvehiclelicense",$data);    
	// return json(array('info'=>upload(makePostData(ROOT_PATH . 'public' . DS . 'uploads'.DS.$path, 'image/jpeg'))));
            } else {
        // 上传失败返回错误信息
                return json(array('state' => 0, 'errmsg' => '上传失败'));
            }
        }


    }
    
    
    public function selCarInfo()
    {
         
        $data = input('post.');
        //判断车牌是否是公司的车
        $res_plate_no = Db::name('order_details')->where('licensenumber',$data['plate_no'])->find();
        if(empty($res_plate_no)){
            //如果是空的，那么就不是公司的车辆
        return json(array('error_code' => 5, 'reason' => '该车辆为非君忆公司车辆！如有疑问请联系管理员@包','result'=>''));
        
        }
        $userinfo = $this->selUserInfo(); 
        
         //判断查询次数是否为0
         if($this->selUserInfo()['query_number']==0){
            return json(array('error_code' => 4, 'reason' => '当前可查询次数为0,请于'.date('Y-m-d H:i:s',intval($userinfo['query_time'])+intval((7*86400))).'后再查询' ,'result'=>''));
            
        }
        $this->selQueryNumber(); 
        
       
                 //获取车牌号查询省市字母简写
        //截取前两位用url_encode 转换
                    // return $res;
        
                    $plate_no = array(
                        'key' => '217fb8552303cb6074f88dbbb5329be7',
                        'hphm' => url_encode(mb_substr($data['plate_no'], 0, 2, "UTF-8"))
                    );
    
        // return json(array('state'=>$plate_no));
            
                    //聚合查询城市前缀
                    $car_city_name = gets("http://v.juhe.cn/sweizhang/carPre?key=217fb8552303cb6074f88dbbb5329be7&hphm={$plate_no['hphm']}");
                    
                    // $car_city_name_arr =  json_decode($car_city_name,true);
                    
                    if ($car_city_name['error_code'] == 0) {
                ##如果返回的错误码不等于0，就返回官方的错误信息
                // return json(array('state' =>$car_city_name['result']['city_code']));
                
                    
        //查询车辆违章
                    $wz = new Wz($this->appkey);
                    $res = json_decode($res, true);
        //根据需要的查询条件，查询车辆的违章信息
                    $city = $car_city_name['result']['city_code']; //城市代码，必传

                    $carno = $data['plate_no']; //车牌号，必传
                    $engineno =$data['engine_no']; //发动机号，需要的城市必传
                    $classno = $data['vin']; //车架号，需要的城市必传
                    // return json(array('city'=>$city,'carno'=>$carno,'engineno'=>$engineno,'classno'=>$classno)); 
                    //如果选中记住输入信息，那就插入数据库,1为记住状态
                        if(isset($data['status'])){ 
                            if($data['status']==1){
                                $data['openid'] = Session::get(['MEMBER'])['openid'];
                                $usercar = $this->userInput();
                                //如果是空的，新增
                                if(empty($usercar)){
                                    Db::name('user_input_car')->insert($data);
                                }else{
                                    //去掉session openid
                                    unset($data['openid']);
        
                                    Db::name('user_input_car')->where('openid',Session::get(['MEMBER'])['openid'])->update($data);
                                }
                            }
                            else{
                                Db::name('user_input_car')->where('openid',Session::get(['MEMBER'])['openid'])->setField('status',0);
                                
                            }
                        }
                    
                    if(strlen($carno)==9){
                        return  gets("http://v.juhe.cn/sweizhang/query?city={$city}&hphm={$carno}&engineno={$engineno}&classno={$classno}&key=217fb8552303cb6074f88dbbb5329be7"); 
                            
                    }
                    else{
                        return  gets("http://v.juhe.cn/sweizhang/query?city={$city}&hphm={$carno}&hpzl=52&engineno={$engineno}&classno={$classno}&key=217fb8552303cb6074f88dbbb5329be7"); 

                    }

                    // $wzResult = $wz->query($city, $carno, $engineno, $classno);
                    // if ($wzResult['error_code'] == 0) {
                    //     if ($wzResult['result']['lists']) {
                    //         //有违章
                          
                    //         foreach ($wzResult['result']['lists'] as $key => $w) {
                    // //以下就是根据实际业务需求修改了
                    //         return json(array('error_code'=>1,'result'=>$w['area'] . " " . $w['date'] . " " . $w['act'] . " " . $w['fen'] . " " . $w['money'],'reason'=>'','query_number'=>$this->selUserInfo()['query_number'])); 
                    //         }
                    //     } else {
                    //         //无违章
                    //         return json(array('error_code' =>2,'result'=>'', 'reason' => '暂无违章记录，恭喜你','query_number'=>$this->selUserInfo()['query_number'])); 

                    //     }
                    // } else {
                    //     //查询不成功
                    //     return json(array('error_code'=>4,'reason'=>$wzResult['error_code'] . ":" . $wzResult['reason'],'result'=>''));
                    // }
                }
                else{
                    return $car_city_name;
                }

    }
    public function selQueryNumber(){
       
        $userinfo = $this->selUserInfo();
         //判断首先时间字段为空的情况下，代表新用户第一次查询，新增时间,次数变为1
        //  if(empty($userinfo['query_time'])){
        //     Db::name('user')->where('openid',session('MEMBER')['openid'])->update(['query_time'=>time(),'query_number'=>1]); 
            
           
        // } 
            //计算第一次时间加上7天 
            $day_7 = intval($userinfo['query_time']) +intval((7*86400)); 
            //如果当前的时间小于第一次设置的时间+7天并且次数小于2，证明是在有效7天内进行第二次查询，次数变为0
            if(time()<$day_7){   
                Db::name('user')->where('openid',Session::get(['MEMBER'])['openid'])->setDec('query_number');
            }
            if(time()>$day_7){
              
                if($userinfo['query_number']==2){
                Db::name('user')->where('openid',Session::get(['MEMBER'])['openid'])->setField('query_time',time());  
                }
                Db::name('user')->where('openid',Session::get(['MEMBER'])['openid'])->setDec('query_number');
                
            }
             
            else{

                //查询
                return json(array('error_code' => 3,'result'=>'', 'reason' => '请于'.date('Y-m-d H:i:s',$day_7).'后再试')); 
            } 
    }
    //手动输入查询
    
 
    public function selUserInfo(){
        return Db::name('user')->where('openid',Session::get(['MEMBER'])['openid'])->find();
        
    }

    //记住输入的表信息
    public function userInput(){
        return  Db::name('user_input_car')->where('openid',Session::get(['MEMBER'])['openid'])->find();
    }
    //查询是否有车牌号
    public function getUserVin(){
        //判断车牌是否是公司的车
        $res_plate_no = Db::name('order_details')->where('licensenumber',input('post.pateNo_val'))->find();
        if(empty($res_plate_no)){
            //如果是空的，那么就不是公司的车辆
            return json(array('error_code' => 1, 'reason' => '该车辆为非君忆公司车辆！如有疑问请联系管理员@包','result'=>'')); 
        }else{
            return json(array('error_code' => 2, 'reason' => '查询成功','result'=>$res_plate_no)); 
            
        }
    }
    /**
     * 
     * curl Post数据 
     * @param $url http地址
     * @param $data    &链接的字符串或者数组
     * @param $timeout 默认请求超时
     * 成功返回字符串
     */
    static function curl_post_contents($url, $data = array(), $timeout = 10)
    {
        $userAgent = 'xx5.com PHP5 (curl) ' . phpversion();
        $referer = $url;
        if (!is_array($data) || !$url) return '';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);            //设置访问的url地址
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);            //设置超时
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);   //用户访问代理 User-Agent
        curl_setopt($ch, CURLOPT_REFERER, $referer);      //设置 referer
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);      //跟踪301
        curl_setopt($ch, CURLOPT_POST, 1);             //指定post数据
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);      //添加变量
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);      //返回结果
        $content = curl_exec($ch);
        curl_close($ch);
        return $content;
    }







}