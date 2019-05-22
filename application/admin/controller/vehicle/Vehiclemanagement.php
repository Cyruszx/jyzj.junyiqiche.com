<?php

namespace app\admin\controller\vehicle;

use app\admin\model\Order;
use app\admin\model\OrderDetails;
use app\admin\model\OrderImg;
use app\admin\model\Admin;
use app\common\controller\Backend;
use fast\Date;
use fast\Http;
use think\Cache;
use think\Db;
use think\Config;
use think\Exception;
use think\exception\PDOException;
use think\exception\ValidateException;
use think\Queue;
use think\response\Json;
use think\Session;
use Endroid\QrCode\QrCode;
use wechat\Wx;
use think\Env;
use app\common\library\Auth;


/**
 *
 *
 * @icon fa fa-circle-o
 */
class Vehiclemanagement extends Backend
{

    /**
     * Vehiclemanagement模型对象
     * @var \app\admin\model\vehicle\Vehiclemanagement
     */
    protected $model = null;
    protected $noNeedRight = ['*'];
    protected $noNeedLogin = ['ceshi', 'sendallviolation', 'update_year', 'test', 'actionWithHelloJob'];

    public function _initialize()
    {

        parent::_initialize();
        $this->model = new \app\admin\model\Order();
        $this->view->assign("customerSourceList", $this->model->getCustomerSourceList());
        $this->view->assign("genderdataList", $this->model->getGenderdataList());
        $this->view->assign("typeList", $this->model->getTypeList());
        $this->view->assign("liftCarStatusList", $this->model->getLiftCarStatusList());

        $customer_service = Admin::field('id,nickname,avatar')
            ->withCount(['violationCount' => function ($q) {
                $q->with(['orderdetails' => function ($details) {
                    $details->where('is_it_illegal', 'violation_of_regulations');
                }]);
            }, 'soonYearCount' => function ($q) {
                $q->with(['orderdetails' => function ($details) {
                    $details->where('annual_inspection_status', 'soon');
                }]);
            }, 'overdueYearCount' => function ($q) {
                $q->with(['orderdetails' => function ($details) {
                    $details->where('annual_inspection_status', 'overdue');
                }]);
            }, 'soonInsuranceCount' => function ($q) {
                $q->with(['orderdetails' => function ($details) {
                    $details->where('traffic_force_insurance_status', 'soon');
                }]);
            }, 'overdueInsuranceCount' => function ($q) {
                $q->with(['orderdetails' => function ($details) {
                    $details->where('traffic_force_insurance_status', 'overdue');
                }]);
            }, 'allCount'])
            ->where('rule_message', 'message10')
            ->select();

        $customer_service = collection($customer_service)->toArray();

        if (!Cache::get('statistics')) {

            Cache::set('statistics', self::statistics(), 43200);

            OrderDetails::field('id,annual_inspection_time,traffic_force_insurance_time,business_insurance_time,annual_inspection_status,traffic_force_insurance_status,business_insurance_status')
                ->where('annual_inspection_status|traffic_force_insurance_status|business_insurance_status', 'neq', 'no_queries')
                ->chunk(500, function ($item) {
                    foreach ($item as $key => $value) {
                        $year_status = $traffic_force_status = $business_status = 'no_queries';
                        if ($value['annual_inspection_status'] != 'no_queries') {

                            $year_status = is_numeric($value['annual_inspection_time']) ? self::check_state($value['annual_inspection_time']) : 'no_queries';
                        }

                        if ($value['traffic_force_insurance_status'] != 'no_queries') {
                            $traffic_force_status = self::check_state($value['traffic_force_insurance_time']);
                        }

//                        if ($value['business_insurance_status'] != 'no_queries') {
//                            $business_status = self::check_state($value['business_insurance_time']);
//                        }

                        OrderDetails::update([
                            'id' => $value['id'],
                            'annual_inspection_status' => $year_status,
                            'traffic_force_insurance_status' => $traffic_force_status,
                            'business_insurance_status' => $business_status,
                        ]);
                    }
                });

        }

        if (!Cache::get('statistics_total_violation')) {
            Cache::set('statistics_total_violation', self::statistics_violation(), 43200);
        }

        //判断是否是客服
//        $rule_message = Admin::where('id', $this->auth->id)->find()['rule_message'];

        $this->view->assign([

            'statistics' => $this->auth->rule_message == 'message10' ? self::statistics(['service_id' => $this->auth->id]) : Cache::get('statistics'),
            'statistics_total_violation' => $this->auth->rule_message == 'message10' ? self::statistics_violation(['service_id' => $this->auth->id]) : Cache::get('statistics_total_violation'),
            'customer_service' => $customer_service,
            'total_fine' => $this->auth->rule_message == 'message10' ? self::sum_violation(['service_id' => $this->auth->id], 'total_fine') : self::sum_violation(null, 'total_fine'),
            'total_points' => $this->auth->rule_message == 'message10' ? self::sum_violation(['service_id' => $this->auth->id], 'total_deduction') : self::sum_violation(null, 'total_deduction'),
            'total_vehicle' => Order::count('id'),
            'assigned_customer_service' => Order::where('service_id', 'not null')->count('id'),
            'unassigned_customer_service' => Order::where('service_id', 'null')->count('id')
        ]);

    }

    /**
     * 违章求和
     * @param null $where
     * @param $sum
     * @return float|int
     */
    protected static function sum_violation($where = null, $sum)
    {
        return OrderDetails::with(['order' => function ($q) use ($where) {
            $q->where($where == null ? null : $where);
        }])->where('is_it_illegal', 'violation_of_regulations')->sum('order_details.' . $sum);
    }

    /**
     * 统计违章
     * @param null $where
     * @return int|string
     * @throws Exception
     */
    protected static function statistics_violation($where = null)
    {
        return OrderDetails::with(['order' => function ($q) use ($where) {
            $q->where($where == null ? null : $where);
        }])->where('is_it_illegal', 'violation_of_regulations')->count('order_details.id');
    }

    /**
     * 统计
     * @return array
     * @throws Exception
     */
    protected static function statistics($where = null)
    {
        return [
            'soon_year' => OrderDetails::with(['order' => function ($q) use ($where) {
                $q->where($where == null ? null : $where);
            }])->where('annual_inspection_status', 'soon')->count('order_details.id'),
            'year_overdue' => OrderDetails::with(['order' => function ($q) use ($where) {
                $q->where($where == null ? null : $where);
            }])->where('annual_inspection_status', 'overdue')->count('order_details.id'),
            'soon_traffic' => OrderDetails::with(['order' => function ($q) use ($where) {
                $q->where($where == null ? null : $where);
            }])->where('traffic_force_insurance_status', 'soon')->count('order_details.id'),
            'traffic_overdue' => OrderDetails::with(['order' => function ($q) use ($where) {
                $q->where($where == null ? null : $where);
            }])->where('traffic_force_insurance_status', 'overdue')->count('order_details.id'),
        ];
    }


    /**得到可操作的管理员ID
     * @return array
     */
    public function getUserId()
    {
        $this->model = model("Admin");
        $back = $this->model->where("rule_message", 'message10')
            ->field("id")
            ->select();

        $backArray = array();
        $backArray['sale'] = array();
        foreach ($back as $value) {
            array_push($backArray['sale'], $value['id']);
        }


        return $backArray;
    }

    /**
     * 查看
     */
    public function index()
    {
        //当前是否为关联查询
        $this->relationSearch = true;

        $this->searchFields = 'username,orderdetails.licensenumber';
        //设置过滤方法
        $this->request->filter(['strip_tags']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $authId = $this->auth->id; // 当前操作员id
//            $getUserId = $this->getUserId();//获取当前可操作权限的id
            $this->model = new \app\admin\model\Order();
            $total = $this->model
                ->with(['orderdetails', 'admin', 'service'])
                ->where($where)
                ->where(function ($query) use ($authId) {
                    //超级管理员
                    if ($this->auth->rule_message == 'message10') $query->where(['service_id' => $authId]);

                    if ($this->auth->rule_message == 'message6') $query->where(['sales_id' => $authId]);

                })
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['orderdetails', 'admin', 'service'])
                ->where($where)
                ->where(function ($query) use ($authId) {
                    //超级管理员
                    if ($this->auth->rule_message == 'message10') $query->where(['service_id' => $authId]);

                    if ($this->auth->rule_message == 'message6') $query->where(['sales_id' => $authId]);

                })
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $key => $row) {


                $row->visible(['feedback', 'kefu', 'id', 'username', 'avatar', 'phone', 'id_card', 'models_name', 'payment', 'monthly', 'nperlist', 'end_money', 'tail_money', 'margin', 'createtime', 'type', 'lift_car_status', 'user_id', 'wx_public_user_id', 'service_id']);
                $row->visible(['orderdetails']);
                $row->getRelation('orderdetails')->visible(['total_deduction', 'file_coding', 'signdate', 'total_contract', 'hostdate', 'licensenumber', 'frame_number', 'engine_number', 'is_mortgage', 'mortgage_people', 'ticketdate', 'supplier', 'tax_amount', 'no_tax_amount', 'pay_taxesdate',
                    'purchase_of_taxes', 'house_fee', 'luqiao_fee', 'insurance_buydate', 'insurance_policy', 'insurance', 'car_boat_tax', 'commercial_insurance_policy',
                    'business_risks', 'subordinate_branch', 'transfer_time', 'is_it_illegal', 'annual_inspection_time',

                    'traffic_force_insurance_time', 'business_insurance_time', 'annual_inspection_status',
                    'traffic_force_insurance_status', 'business_insurance_status', 'reson_query_fail', 'update_violation_time', 'total_fine', 'is_repeat', 'feedback']);

                $row->visible(['admin']);
                $row->getRelation('admin')->visible(['nickname', 'avatar']);
                $row->visible(['service']);
                $row->getRelation('service')->visible(['nickname', 'avatar']);
                $list[$key]['kefu'] = $authId == $list[$key]['service_id'] ? 1 : 0;
                //客服反馈信息
                $list[$key]['feedback'] = json_decode($list[$key]['orderdetails']['feedback'], true);

            }
            $list = collection($list)->toArray();

            $result = array("total" => $total, "rows" => $list, 'else' => array_merge($this->auth->rule_message == 'message10' ? self::statistics(['service_id' => $this->auth->id]) : Cache::get('statistics'), ['statistics_total_violation' => $this->auth->rule_message == 'message10' ? self::statistics_violation(['service_id' => $this->auth->id]) : Cache::get('statistics_total_violation')]));


            return json($result);
        }

        return $this->view->fetch();
    }


    /**
     * 生成二维码,后台展示授权码
     * @throws \Endroid\QrCode\Exceptions\ImageTypeInvalidException
     */
    public function setqrcode()
    {

        if ($this->request->isAjax()) {
            $params = $this->request->post();
            $authorization_img = Order::get($params['order_id'])->authorization_img;
            if ($authorization_img) $this->success('创建成功', '', $authorization_img);
            $time = date('YmdHis');
            $qrCode = new QrCode();
            $qrCode->setText($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['SERVER_NAME'] . '/?order_id=' . $params['order_id'])
                ->setSize(350)
                ->setPadding(10)
                ->setErrorCorrection('high')
                ->setForegroundColor(array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 0))
                ->setBackgroundColor(array('r' => 255, 'g' => 255, 'b' => 255, 'a' => 0))
                ->setLabel('需由客户 ' . $params['username'] . ' 扫码授权')
                ->setLabelFontPath(VENDOR_PATH . 'endroid/qr-code/assets/font/MSYHBD.TTC')
                ->setLabelFontSize(10)
                ->setImageType(\Endroid\QrCode\QrCode::IMAGE_TYPE_PNG);
            $fileName = DS . 'uploads' . DS . 'qrcode' . DS . $time . '_' . 'o_id' . $params['order_id'] . '.png';
            $qrCode->save(ROOT_PATH . 'public' . $fileName);
            if ($qrCode) {
                Order::update(['id' => $params['order_id'], 'authorization_img' => $fileName]) ? $this->success('创建成功', '', $fileName) : $this->error('创建失败');
            }
            $this->error('未知错误');
        }
    }

    /**
     * 公众号授权
     * @throws \Endroid\QrCode\Exceptions\ImageTypeInvalidException
     * @throws \think\exception\DbException
     */
    public function public_qr_code()
    {
        if ($this->request->isAjax()) {

            $params = $this->request->post();
            $public_img = Order::get($params['order_id'])->public_img;
            if ($public_img) $this->success('创建成功', '', $public_img);
            $time = date('YmdHis');
            $qrCode = new QrCode();
            $qrCode->setText($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['SERVER_NAME'] . '/index/index/index/order_id/' . $params['order_id'])
                ->setSize(250)
                ->setPadding(10)
                ->setErrorCorrection('high')
                ->setForegroundColor(array('r' => 108, 'g' => 182, 'b' => 229, 'a' => 0))
                ->setBackgroundColor(array('r' => 255, 'g' => 255, 'b' => 255, 'a' => 0))
                ->setLabel(htmlspecialchars_decode('需由客户：' . $params['username'] . ' 扫码授权'))
                ->setLabelFontPath(VENDOR_PATH . 'endroid/qr-code/assets/font/MSYHBD.TTC')
                ->setLabelFontSize(10)
                ->setImageType(\Endroid\QrCode\QrCode::IMAGE_TYPE_PNG);
            $fileName = DS . 'uploads' . DS . 'qrcode' . DS . $time . '_' . 'o_id' . $params['order_id'] . '.png';
            $qrCode->save(ROOT_PATH . 'public' . $fileName);
            if ($qrCode) {
                Order::update(['id' => $params['order_id'], 'public_img' => $fileName]) ? $this->success('创建成功', '', $fileName) : $this->error('创建失败');
            }
            $this->error('未知错误');
        }
    }

    /**
     * 确认提车
     * @param null $ids
     * @return string
     * @throws Exception
     * @throws \think\exception\DbException
     */
    public function edit($ids = null)
    {

        $row = $this->model->get($ids);
        //提车时，查询一次违章信息
//        $detail = $this->model->field('username')
//            ->with(['orderdetails' => function ($q) use ($ids) {
//                $q->withField('order_id,licensenumber,frame_number,engine_number')->where('order_id', $ids);
//            }])->find();

//         pr($data);
//         die;
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $adminIds = $this->getDataLimitAdminIds();
        if (is_array($adminIds)) {
            if (!in_array($row[$this->dataLimitField], $adminIds)) {
                $this->error(__('You have no permission'));
            }
        }
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if ($params) {
                $params = $this->preExcludeFields($params);
                $params['order_id'] = $ids;
                $params['admin_id'] = $this->auth->id;
                if ($params['annual_inspection_time']) $params['annual_inspection_time'] = strtotime($params['annual_inspection_time']);

                if ($params['traffic_force_insurance_time']) $params['traffic_force_insurance_time'] = strtotime($params['traffic_force_insurance_time']);

                $result = false;
                Db::startTrans();
                try {
                    //是否采用模型验证
                    if ($this->modelValidate) {
                        $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                        $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.edit' : $name) : $this->modelValidate;
                        $row->validate($validate);
                    }

                    $order_details = new OrderDetails();
                    $exists = $order_details->getByOrder_id($ids);
                    if ($exists) {
                        $order_details->allowField(true)->save($params,['id'=>$exists->id]);
                    } else {
                        $order_details->allowField(true)->save($params);
                    }


                    $data[] = [
                        'hphm' => mb_substr($params['licensenumber'], 0, 2),
                        'hphms' => $params['licensenumber'],
                        'engineno' => $params['engine_number'],
                        'classno' => $params['frame_number'],
                        'order_id' => $ids,
                        'username' => $row->username,
                    ];

                    $row->lift_car_status = 'yes';
                    $row->sales_id = $row->admin_id;
                    $result = $row->save();
                    illegal($data);
//                    self::illegal($data);
                    Db::commit();
                } catch (ValidateException $e) {
                    Db::rollback();
                    $this->error($e->getMessage());
                } catch (PDOException $e) {
                    Db::rollback();
                    $this->error($e->getMessage());
                } catch (Exception $e) {
                    Db::rollback();
                    $this->error($e->getMessage());
                }
                if ($result) {
                    $this->success();
                } else {
                    $this->error();
                }
            }
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $list = OrderDetails::getByOrder_id($ids);
        $this->view->assign([
            'type' => $row->type,
            'row' => $list
        ]);
        return $this->view->fetch();
    }


    /**
     * 修改资料
     * @param null $ids
     * @return string
     * @throws Exception
     * @throws \think\exception\DbException
     */
    public function modifying_data($ids = null)
    {
        $row = OrderDetails::getByOrder_id($ids);
        $order = Order::where('id', $ids)->find();

        $row['id_card'] = $order['id_card'];

        $row['phone'] = $order['phone'];
        $row['username'] = $order['username'];
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $adminIds = $this->getDataLimitAdminIds();
        if (is_array($adminIds)) {
            if (!in_array($row[$this->dataLimitField], $adminIds)) {
                $this->error(__('You have no permission'));
            }
        }
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if ($params) {
                $params = $this->preExcludeFields($params);
                $result = false;
                Db::startTrans();
                try {
                    //是否采用模型验证
                    if ($this->modelValidate) {
                        $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                        $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.edit' : $name) : $this->modelValidate;
                        $row->validate($validate);
                    }
                    $params['annual_inspection_time'] = $params['annual_inspection_time'] ? strtotime($params['annual_inspection_time']) : null;
                    $params['traffic_force_insurance_time'] = $params['traffic_force_insurance_time'] ? strtotime($params['traffic_force_insurance_time']) : null;
                    $params['business_insurance_time'] = $params['business_insurance_time'] ? strtotime($params['business_insurance_time']) : null;
                    $result = $row->allowField(true)->save($params);
                    $res = Order::where('id', $ids)->update(['phone' => $params['phone'], 'username' => $params['username'], 'id_card' => $params['id_card']]);
                    Db::commit();
                } catch (ValidateException $e) {
                    Db::rollback();
                    $this->error($e->getMessage());
                } catch (PDOException $e) {
                    Db::rollback();
                    $this->error($e->getMessage());
                } catch (Exception $e) {
                    Db::rollback();
                    $this->error($e->getMessage());
                }

                //修改各种日期的状态
                $year_status = $traffic_force_status = $business_status = 'no_queries';
                if ($params['annual_inspection_time']) {
                    $year_status = is_numeric($params['annual_inspection_time']) ? self::check_state($params['annual_inspection_time']) : 'no_queries';
                }
                if ($params['traffic_force_insurance_time']) {
                    $traffic_force_status = self::check_state($params['traffic_force_insurance_time']);
                }
                if ($params['business_insurance_time']) {
                    $business_status = self::check_state($params['business_insurance_time']);
                }
                $row->annual_inspection_status = $year_status;
                $row->traffic_force_insurance_status = $traffic_force_status;
                $row->business_insurance_status = $business_status;
                $row->save();

                Cache::rm('statistics');

                Cache::set('statistics', self::statistics(), 43200);
                $this->success();

            }
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $this->view->assign([
            "row" => $row,
            'type' => Order::get($ids)->type,
            'mortgageList' => ['是' => '是', '否' => '否']
        ]);

        return $this->view->fetch();
    }

    /**
     * 检查状态
     * @param $checktime
     * @return string
     */
    public static function check_state($checktime)
    {
        $time = time();
        $last_month = date('Y-m-d', $checktime);
        $last_month = strtotime("{$last_month} -2 month");
        $status_year = '';
        if ($time < $last_month) {
            $status_year = 'normal';
        } else if ($time > $last_month && $time < $checktime) {
            $status_year = 'soon';
        } else if ($time > $checktime) {
            $status_year = 'overdue';
        }

        return $status_year;
    }

    /**
     * 查看客户信息
     * @param null $ids
     * @return string
     * @throws Exception
     * @throws \think\exception\DbException
     */
    public function view_information($ids = null)
    {
        $row = $this->model->with(['orderimg'])->find($ids);

        $order = new \app\admin\model\Order;
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $adminIds = $this->getDataLimitAdminIds();
        if (is_array($adminIds)) {
            if (!in_array($row[$this->dataLimitField], $adminIds)) {
                $this->error(__('You have no permission'));
            }
        }
        if ($this->request->isPost()) {
            $this->success();
        }
        $car_type_list = $order->getTypeList();

        $car_type = '';
        foreach ($car_type_list as $k => $v) {
            if ($row['type'] == $k) {
                $car_type = $v;
                break;
            }
        }
        $this->view->assign([
            "row" => $row,
            'customerSourceList' => $order->getCustomerSourceList(),
            "genderdataList" => $order->getGenderdataList(),
            "nperlistList" => $this->getNperlistList(),
            'car_type' => $car_type
        ]);
        return $this->view->fetch();
    }

    public function getNperlistList()
    {
        return ['12' => __('12'), '24' => __('24'), '36' => __('36'), '48' => __('48'), '60' => __('60')];
    }

    /**
     * 获取违章信息(单个、批量)
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function sendMessagePerson()
    {
        if ($this->request->isAjax()) {

            $params = $this->request->post()['ids'];

            $illegal = illegal($params);

            $wx_public_user_id = Order::where('id', $params[0]['order_id'])->find()['wx_public_user_id'] ? 1 : 0;

            $this->success('', '', ['wx_public_user_id' => $wx_public_user_id, 'error_num' => $illegal['error_num'], 'success_num' => $illegal['success_num'], 'query_record' => $illegal['query_record']]);
        }
    }
//    /**
//     * 获取违章信息(单个、批量)
//     * @throws \think\Exception
//     * @throws \think\db\exception\DataNotFoundException
//     * @throws \think\db\exception\ModelNotFoundException
//     * @throws \think\exception\DbException
//     * @throws \think\exception\PDOException
//     */
//    public function sendMessagePerson()
//    {
//        if ($this->request->isAjax()) {
//
//            $params = $this->request->post()['ids'];
//            // 1.当前任务将由哪个类来负责处理。
//            //   当轮到该任务时，系统将生成一个该类的实例，并调用其 fire 方法
//            $jobHandlerClassName = 'app\job\CommonJob@sendMessagePerson';
//
//            // 2.当前任务归属的队列名称，如果为新队列，会自动创建
//            $jobQueueName = "sendMessagePerson";
//
//            // 3.当前任务所需的业务数据 . 不能为 resource 类型，其他类型最终将转化为json形式的字符串
//            //   ( jobData 为对象时，存储其public属性的键值对 )
////        $jobData       	  = [ 'ts' => time(), 'bizId' => uniqid() , 'a' => 1 ] ;
//
//            // 4.将该任务推送到消息队列，等待对应的消费者去执行
//            $isPushed = Queue::push($jobHandlerClassName, $params, $jobQueueName);
//
//            // database 驱动时，返回值为 1|false  ;   redis 驱动时，返回值为 随机字符串|false
//            if ($isPushed !== false) {
//                $this->success('1');
////                $illegal = illegal($params);
////
////                $wx_public_user_id = Order::where('id', $params[0]['order_id'])->find()['wx_public_user_id'] ? 1 : 0;
////
////                $this->success('', '', ['wx_public_user_id' => $wx_public_user_id, 'error_num' => $illegal['error_num'], 'success_num' => $illegal['success_num'], 'query_record' => $illegal['query_record']]);
//
//
//                echo date('Y-m-d H:i:s') . " a new Hello Job is Pushed to the MQ" . "<br>";
//            } else {
//                $this->error('Oops, something went wrong.');
//
//            }
//
//        }
//        return;
//    }

    public function violation_details($ids = null)
    {
//        $detail = Db::name('violation_inquiry')
//            ->where('id', $ids)
//            ->field('username,phone,peccancy_detail,total_deduction,total_fine')
//            ->find();

        $detail = $this->model->field('username,phone')
            ->with(['orderdetails' => function ($q) {
                $q->withField('licensenumber,total_deduction,total_fine,violation_details');
            }])->find($ids);

        $details = json_decode($detail['orderdetails']['violation_details'], true);


//        $score = 0;
//        $money = 0;
//        $no_handle = array();
//        $handle = array();
//        foreach ($details as $k => $v) {
//            if ($v['handled'] == 0) {
//                $score += floatval($v['fen']);
//                $money += floatval($v['money']);
//                array_push($no_handle, $v);
//            } else {
//                array_push($handle, $v);
//            }
//        }
//
//
//        $details = array_merge($no_handle, $handle);

        $this->view->assign([
            'detail' => $details,
            'phone' => $detail['phone'],
            'licensenumber' => $detail['orderdetails']['licensenumber'],
            'username' => $detail['username'],
            'total_fine' => $detail['orderdetails']['total_fine'],
            'total_deduction' => $detail['orderdetails']['total_deduction']
        ]);

        return $this->view->fetch();
    }

    //查看客户信息
    public function customer_information($ids = null)
    {
        $row = Db::name('order')->alias('a')
            ->join('order_details b', 'b.order_id=a.id', 'LEFT')
            ->join('order_img c', 'c.order_id = a.id', 'LEFT')
            ->field('a.customer_source,a.financial_name,a.username,a.phone,a.id_card,a.genderdata,a.city,a.detailed_address,a.models_name,a.payment,a.monthly,a.nperlist,a.gps,
                a.end_money,a.tail_money,a.margin,a.decoration,a.rent,a.deposit,a.family_members,a.family_members2,a.turn_to_introduce_name,a.turn_to_introduce_phone,
                a.turn_to_introduce_card,a.order_createtime,a.delivery_datetime,a.note_sales,a.type,a.lift_car_status,
                b.file_coding,b.signdate,b.total_contract,b.hostdate,b.licensenumber,b.frame_number,b.engine_number,b.mortgage_people,b.ticketdate,b.supplier,
                b.tax_amount,b.no_tax_amount,b.pay_taxesdate,b.purchase_of_taxes,b.house_fee,b.luqiao_fee,b.insurance_buydate,b.insurance_policy,b.insurance,b.car_boat_tax,
                b.commercial_insurance_policy,b.business_risks,b.subordinate_branch,b.transfer_time,b.annual_inspection_time,b.traffic_force_insurance_time,b.business_insurance_time,
                
                c.id_cardimages,c.drivers_licenseimages,c.application_formimages,c.commit_to_authimages,c.bank_cardimages,c.mate_id_cardimages,c.residence_bookletimages,
                c.call_listfiles,c.no_criminal_recordimages,c.car_purchase_confirmationimages,c.deposit_contractimages,c.deposit_receiptimages,
                c.undertakingimages,c.faceimages')
            ->where('a.id', $ids)
            ->find();

        // pr($row);
        if (!$row) $this->error(__('No Results were found'));

        //身份证正反面（多图）
        $id_cardimages = $row['id_cardimages'] == '' ? [] : explode(',', $row['id_cardimages']);
        //驾照正副页（多图）
        $drivers_licenseimages = $row['drivers_licenseimages'] == '' ? [] : explode(',', $row['drivers_licenseimages']);
        //申请表（多图）
        $application_formimages = $row['application_formimages'] == '' ? [] : explode(',', $row['application_formimages']);
        //承诺与授权书（多图）
        $commit_to_authimages = $row['commit_to_authimages'] == '' ? [] : explode(',', $row['commit_to_authimages']);
        //银行卡照（可多图）
        $bank_cardimages = $row['bank_cardimages'] == '' ? [] : explode(',', $row['bank_cardimages']);
        //配偶的身份证正反面（多图）
        $mate_id_cardimages = $row['mate_id_cardimages'] == '' ? [] : explode(',', $row['mate_id_cardimages']);
        //户口簿【首页、主人页、本人页】
        $residence_bookletimages = $row['residence_bookletimages'] == '' ? [] : explode(',', $row['residence_bookletimages']);
        //通话清单（文件上传）
        $call_listfiles = $row['call_listfiles'] == '' ? [] : explode(',', $row['call_listfiles']);
        //无犯罪记录书
        $no_criminal_recordimages = $row['no_criminal_recordimages'] == '' ? [] : explode(',', $row['no_criminal_recordimages']);
        //购车确认书
        $car_purchase_confirmationimages = $row['car_purchase_confirmationimages'] == '' ? [] : explode(',', $row['car_purchase_confirmationimages']);
        //定金合同（多图）
        $deposit_contractimages = $row['deposit_contractimages'] == '' ? [] : explode(',', $row['deposit_contractimages']);
        //定金收据上传
        $deposit_receiptimages = $row['deposit_receiptimages'] == '' ? [] : explode(',', $row['deposit_receiptimages']);
        //承诺书
        $undertakingimages = $row['undertakingimages'] == '' ? [] : explode(',', $row['undertakingimages']);
        //面签照
        $faceimages = $row['faceimages'] == '' ? [] : explode(',', $row['faceimages']);


        $this->view->assign([
            "row" => $row,
            'cdnurl' => Config::get('upload')['cdnurl'],
            'id_cardimages' => $id_cardimages,
            'drivers_licenseimages' => $drivers_licenseimages,
            'application_formimages' => $application_formimages,
            'commit_to_authimages' => $commit_to_authimages,
            'bank_cardimages' => $bank_cardimages,
            'mate_id_cardimages' => $mate_id_cardimages,
            'residence_bookletimages' => $residence_bookletimages,
            'call_listfiles' => $call_listfiles,
            'no_criminal_recordimages' => $no_criminal_recordimages,
            'car_purchase_confirmationimages' => $car_purchase_confirmationimages,
            'deposit_contractimages' => $deposit_contractimages,
            'deposit_receiptimages' => $deposit_receiptimages,
            'undertakingimages' => $undertakingimages,
            'faceimages' => $faceimages
        ]);


        return $this->view->fetch();

    }


    /**
     * 发送小程序模板消息
     * @param $data
     * @return array
     */

    public static function sendXcxTemplateMsg($data = '')
    {
        $appid = Env::get('wx_public.appid');
        $secret = Env::get('wx_public.secret');
        $wx = new wx($appid, $secret);
        $access_token = $wx->getWxtoken()['access_token'];
        // pr($access_token);
        // die;
        $url = "https://api.weixin.qq.com/cgi-bin/message/template/send?access_token={$access_token}";
        return posts($url, $data);
    }


    /**
     * 获取用户openid
     * @param $user_id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function getOpenid($wx_public_user_id)
    {
        return Db::name('wx_public_user')->where(['id' => $wx_public_user_id])->find()['openid'];
    }

    /**
     * 发送违章公众号模板消息
     */
    public function sendviolation($ids = '')
    {
        $detail = Collection($this->model->field('username,phone,wx_public_user_id,models_name')
            ->with(['orderdetails' => function ($q) use ($ids) {
                $q->withField('licensenumber,total_deduction,total_fine,violation_details')->where('order_id', 'in', $ids);
            }])->select())->toArray();
        //是否存在数据
        if ($detail) {
            foreach ($detail as $key => $value) {

                $openid = $this->getOpenid($value['wx_public_user_id']);

                //是否有openid
                if ($openid) {
                    $time = date('Y-m-d', time());
                    $first = $value['username'] . '师傅您好，截止到' . $time . '，您车牌号为＂' . $value['orderdetails']['licensenumber'] . '＂的车辆有以下未处理的违章信息';

                    $details = json_decode($value['orderdetails']['violation_details'], true);

                    $count = count($details);
                    $temp_msg = array(
                        'touser' => "{$openid}",
                        'template_id' => "hTlWqtgPyt6wr1KNdctpFkilUZbc0f9lDNtosGaH1-4",
                        'url' => 'https://jyzj.junyiqiche.com/index/',
                        'data' => array(
                            'first' => array(
                                "value" => "{$first}",
                                "color" => '#1E9FFF'
                            ),
                            'keyword1' => array(
                                "value" => "{$value['orderdetails']['licensenumber']}",
                            ),
                            'keyword2' => array(
                                "value" => "{$count}",
                                "color" => "#FF5722"
                            ),
                            'keyword3' => array(
                                "value" => "{$value['orderdetails']['total_deduction']}",
                                "color" => "#FF5722"
                            ),
                            'keyword4' => array(
                                "value" => "{$value['orderdetails']['total_fine']}元",
                                "color" => "#FF5722"
                            ),
                            'keyword5' => array(
                                "value" => "{$time}",
                            ),
                            "remark" => array(
                                "value" => "点击查看违章详情，（公司户处理违章需要到公司拿：营业执照副本（盖鲜章）、委托书）",
                            )

                        ),
                    );

                    $res = $this->sendXcxTemplateMsg(json_encode($temp_msg));

                }

            }

            $this->success();
        }

    }

    /**得到可操作的ID
     * 客服---管理员
     * @return array
     */
    public function getSeiviceId()
    {
        $this->model = model("Admin");
        $seivice = $this->model->where("rule_message", 'message10')
            ->column('id');

        return $seivice;
    }


    /**
     * 可以发送的违章客户信息展示
     */
    public function canviolation()
    {
        //当前是否为关联查询
        $this->relationSearch = true;

        $this->searchFields = 'username,orderdetails.licensenumber';
        //设置过滤方法
        $this->request->filter(['strip_tags']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $authId = $this->auth->id; // 当前操作员id
            $getSeiviceId = $this->getSeiviceId();
            // pr($getSeiviceId);
            // die;
            $this->model = new \app\admin\model\Order();
            $total = $this->model
                ->with(['orderdetails' => function ($q) {
                    $q->where(['is_it_illegal' => 'violation_of_regulations']);
                }, 'admin'])
                ->where($where)
                ->where(function ($query) use ($authId, $getSeiviceId) {

                    if (in_array($authId, $getSeiviceId)) {

                        $query->where(['wx_public_user_id' => ['neq', 'null'], 'service_id' => $authId]);

                    } else {

                        $query->where(['wx_public_user_id' => ['neq', 'null']]);

                    }

                })
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['orderdetails' => function ($q) {
                    $q->where(['is_it_illegal' => 'violation_of_regulations']);
                }, 'admin'])
                ->where($where)
                ->where(function ($query) use ($authId, $getSeiviceId) {

                    if (in_array($authId, $getSeiviceId)) {

                        $query->where(['wx_public_user_id' => ['neq', 'null'], 'service_id' => $authId]);

                    } else {

                        $query->where(['wx_public_user_id' => ['neq', 'null']]);

                    }

                })
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $row) {
                $row->visible(['id', 'username', 'avatar', 'phone', 'id_card', 'models_name', 'payment', 'monthly', 'nperlist', 'end_money', 'tail_money', 'margin', 'createtime', 'type', 'lift_car_status', 'user_id']);
                $row->visible(['orderdetails']);
                $row->getRelation('orderdetails')->visible(['file_coding', 'signdate', 'total_contract', 'hostdate', 'licensenumber', 'frame_number', 'engine_number', 'is_mortgage', 'mortgage_people', 'ticketdate', 'supplier', 'tax_amount', 'no_tax_amount', 'pay_taxesdate',
                    'purchase_of_taxes', 'house_fee', 'luqiao_fee', 'insurance_buydate', 'insurance_policy', 'insurance', 'car_boat_tax', 'commercial_insurance_policy',
                    'business_risks', 'subordinate_branch', 'transfer_time', 'is_it_illegal', 'annual_inspection_time',
                    'traffic_force_insurance_time', 'business_insurance_time', 'annual_inspection_status',
                    'traffic_force_insurance_status', 'business_insurance_status', 'reson_query_fail', 'update_violation_time']);

                $row->visible(['admin']);
                $row->getRelation('admin')->visible(['nickname', 'avatar']);
            }
            $list = collection($list)->toArray();
            // pr($list);
            // die;
            $result = array("total" => $total, "rows" => $list, 'else' => array_merge(Cache::get('statistics'), ['statistics_total_violation' => Cache::get('statistics_total_violation')]));

            return json($result);
        }

        return $this->view->fetch();
    }


    /**
     * 全网发送违章公众号模板消息
     */
    public function sendallviolation()
    {
        $detail = Collection($this->model->where('wx_public_user_id', 'not null')->field('username,phone,wx_public_user_id,models_name')
            ->with(['orderdetails' => function ($q) {
                $q->withField('licensenumber,total_deduction,total_fine,violation_details')->where(['is_it_illegal' => 'violation_of_regulations']);
            }])->select())->toArray();

        //是否存在数据
        if ($detail) {
            foreach ($detail as $key => $value) {

                $openid = $this->getOpenid($value['wx_public_user_id']);

                //是否有openid
                if ($openid) {
                    $time = date('Y-m-d', time());
                    $first = $value['username'] . '师傅您好，截止到' . $time . '，您车牌号为＂' . $value['orderdetails']['licensenumber'] . '＂的车辆有以下未处理的违章信息';

                    $details = json_decode($value['orderdetails']['violation_details'], true);
                    $count = count($details);

                    $temp_msg = array(
                        'touser' => "{$openid}",
                        'template_id' => "hTlWqtgPyt6wr1KNdctpFkilUZbc0f9lDNtosGaH1-4",
                        'data' => array(
                            'first' => array(
                                "value" => "{$first}",
                                "color" => '#1E9FFF'
                            ),
                            'keyword1' => array(
                                "value" => "{$value['orderdetails']['licensenumber']}",
                            ),
                            'keyword2' => array(
                                "value" => "{$count}",
                                "color" => "#ff0000"
                            ),
                            'keyword3' => array(
                                "value" => "{$value['orderdetails']['total_deduction']}",
                                "color" => "#ff0000"
                            ),
                            'keyword4' => array(
                                "value" => "{$value['orderdetails']['total_fine']}元",
                                "color" => "#ff0000"
                            ),
                            'keyword5' => array(
                                "value" => "{$time}",
                            ),
                            "remark" => array(
                                "value" => "点击查看违章详情，（公司户处理违章需要到公司拿：营业执照副本（盖鲜章）、委托书）",
                            )

                        ),
                    );

                    $res = $this->sendXcxTemplateMsg(json_encode($temp_msg));

                }

            }
            die;
        }

    }


    /**
     * 单个发送违章公众号模板消息
     */
    public function sendoneviolation()
    {
        if ($this->request->isAjax()) {

            $ids = $this->request->post();
            $id = $ids['id'];
            // pr($id);
            // die;
            $detail = Collection($this->model->field('username,phone,wx_public_user_id,models_name')
                ->with(['orderdetails' => function ($q) use ($id) {
                    $q->withField('licensenumber,total_deduction,total_fine,violation_details')->where(['is_it_illegal' => 'violation_of_regulations', 'order_id' => $id]);
                }])->select())->toArray();
            // pr($detail);
            // die;
            //是否存在数据
            if ($detail) {

                $openid = $this->getOpenid($detail[0]['wx_public_user_id']);

                //是否有openid
                if ($openid) {
                    $time = date('Y-m-d', time());
                    $first = $value['username'] . '师傅您好，截止到' . $time . '，您车牌号为＂' . $value['orderdetails']['licensenumber'] . '＂的车辆有以下未处理的违章信息';

                    $details = json_decode($detail[0]['orderdetails']['violation_details'], true);
                    $count = count($details);

                    $temp_msg = array(
                        'touser' => "{$openid}",
                        'template_id' => "hTlWqtgPyt6wr1KNdctpFkilUZbc0f9lDNtosGaH1-4",
                        'data' => array(
                            'first' => array(
                                "value" => "{$first}",
                                "color" => '#1E9FFF'
                            ),
                            'keyword1' => array(
                                "value" => "{$detail[0]['orderdetails']['licensenumber']}",
                            ),
                            'keyword2' => array(
                                "value" => "{$count}",
                                "color" => "#ff0000"
                            ),
                            'keyword3' => array(
                                "value" => "{$detail[0]['orderdetails']['total_deduction']}",
                                "color" => "#ff0000"
                            ),
                            'keyword4' => array(
                                "value" => "{$detail[0]['orderdetails']['total_fine']}元",
                                "color" => "#ff0000"
                            ),
                            'keyword5' => array(
                                "value" => "{$time}",
                            ),
                            "remark" => array(
                                "value" => "点击查看违章详情，（公司户处理违章需要到公司拿：营业执照副本（盖鲜章）、委托书）",
                            )

                        ),
                    );

                    $res = $this->sendXcxTemplateMsg(json_encode($temp_msg));

                    if ($res['errcode'] == 0) {
                        $this->success('操作成功');
                    }

                } else {
                    $this->success('未认证，请前往君忆之家公众号进行认证');
                }

            } else {
                $this->success('没有违章可推送');
            }
        }
    }


    /**
     * 导出excel
     * @throws \PHPExcel_Exception
     * @throws \PHPExcel_Reader_Exception
     * @throws \PHPExcel_Writer_Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    function exportOrderExcel()
    {
        $ids = $this->request->post('ids');

        $authId = $this->auth->id;

        $rule_message = Admin::field('rule_message')->where('id', $authId)->find()['rule_message'];

        if ($ids == 'all' && $rule_message == 'message10') {

            $result = Order::field('id')->where('service_id', $authId)->select();

            foreach ($result as $k => $v) {
                $service_ids .= $v['id'] . ',';
            }

        } else if ($ids == 'all') {
            $service_ids = null;
        }

        $info = collection(Order::field('id,createtime,username,phone,models_name')
            ->with(['admin' => function ($q) {
                $q->withField('nickname');
            }, 'orderdetails' => function ($q) {
                $q->withField('file_coding,licensenumber,frame_number,engine_number,is_it_illegal,total_deduction,total_fine,update_violation_time,annual_inspection_time,traffic_force_insurance_time');
            }])->select($ids == 'all' ? $service_ids : $ids))->toArray();

        // 新建一个excel对象 大神已经加入了PHPExcel 不用引了 直接用！
        $objPHPExcel = new \PHPExcel();  //在vendor目录下 \不能少 否则报错
        // 设置文档的相关信息
        $objPHPExcel->getProperties()->setCreator("楼主666")/*设置作者*/
        ->setLastModifiedBy("楼主666")/*最后修改*/
        ->setTitle("楼主666")/*题目*/
        ->setSubject("楼主666")/*主题*/
        ->setDescription("楼主666")/*描述*/
        ->setKeywords("楼主666")/*关键词*/
        ->setCategory("楼主666");/*类别*/

        $objPHPExcel->getDefaultStyle()->getFont()->setName('微软雅黑');//字体
        /*设置表头*/
        $objPHPExcel->getActiveSheet()->mergeCells('A1:P1');//合并第一行的单元格
//        $objPHPExcel->getActiveSheet()->mergeCells('A2:P2');//合并第二行的单元格
        $objPHPExcel->getActiveSheet()->getStyle('A1')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $objPHPExcel->setActiveSheetIndex(0)->setCellValue('A1', '客户信息表');//标题
        $objPHPExcel->getActiveSheet()->getRowDimension('1')->setRowHeight(30);      // 第一行的默认高度

        //第二行的内容和格式
//        $objPHPExcel->setActiveSheetIndex(0)->setCellValue('A2', '');
        $objPHPExcel->getActiveSheet()->getRowDimension('2')->setRowHeight(30);/*设置行高*/
        $myrow = 2;/*表头所需要行数的变量，方便以后修改*/

        /*表头数据填充*/
//        $objPHPExcel->getActiveSheet()->getRowDimension('2')->setRowHeight(30);/*设置行高*/
        $objPHPExcel->setActiveSheetIndex(0)//设置一张sheet为活动表 添加表头信息

        ->setCellValue('A' . $myrow, '序号')
            ->setCellValue('B' . $myrow, '订单创建时间')
            ->setCellValue('C' . $myrow, '档案编码')
            ->setCellValue('D' . $myrow, '客户姓名')
            ->setCellValue('E' . $myrow, '联系方式')
            ->setCellValue('F' . $myrow, '车牌号')
            ->setCellValue('G' . $myrow, '车架号')
            ->setCellValue('H' . $myrow, '发动机号')
            ->setCellValue('I' . $myrow, '所属销售')
            ->setCellValue('J' . $myrow, '规格型号')
            ->setCellValue('K' . $myrow, '违章状态')
            ->setCellValue('L' . $myrow, '总扣分')
            ->setCellValue('M' . $myrow, '总罚款')
            ->setCellValue('N' . $myrow, '最后查询违章时间')
            ->setCellValue('O' . $myrow, '年检截至日期')
            ->setCellValue('P' . $myrow, '保险截至日期');

        // 关键数据
        $myrow = $myrow + 1; //刚刚设置的行变量
        $mynum = 1;//序号

        //遍历接收的数据，并写入到对应的单元格内
        foreach ($info as $key => $value) {

            $value['createtime'] = $value['createtime'] ? date('Y-m-d', $value['createtime']) : '';

            $value['orderdetails']['update_violation_time'] = $value['orderdetails']['update_violation_time'] ? date('Y-m-d', $value['orderdetails']['update_violation_time']) : '';
            $value['orderdetails']['annual_inspection_time'] = is_numeric($value['orderdetails']['annual_inspection_time']) ? date('Y-m-d', $value['orderdetails']['annual_inspection_time']) : $value['orderdetails']['annual_inspection_time'];
            $value['orderdetails']['traffic_force_insurance_time'] = $value['orderdetails']['traffic_force_insurance_time'] ? date('Y-m-d', $value['orderdetails']['traffic_force_insurance_time']) : '';

            switch ($value['orderdetails']['is_it_illegal']) {
                case 'no_violation':
                    $value['orderdetails']['is_it_illegal'] = '无违章';
                    break;
                case 'violation_of_regulations':
                    $value['orderdetails']['is_it_illegal'] = '有违章';
                    break;
                case 'query_failed':
                    $value['orderdetails']['is_it_illegal'] = '查询违章信息失败';
                    break;
                default:
                    $value['orderdetails']['is_it_illegal'] = '';
                    break;
            }

            $objPHPExcel->setActiveSheetIndex(0)
                ->setCellValue('A' . $myrow, $mynum)
                ->setCellValue('B' . $myrow, $value['createtime'])
                ->setCellValue('C' . $myrow, $value['orderdetails']['file_coding'])
                ->setCellValue('D' . $myrow, $value['username'])
                ->setCellValue('E' . $myrow, $value['phone'])
                ->setCellValue('F' . $myrow, $value['orderdetails']['licensenumber'])
                ->setCellValue('G' . $myrow, $value['orderdetails']['frame_number'])
                ->setCellValue('H' . $myrow, $value['orderdetails']['engine_number'])
                ->setCellValue('I' . $myrow, $value['admin']['nickname'])
                ->setCellValue('J' . $myrow, $value['models_name'])
                ->setCellValue('K' . $myrow, $value['orderdetails']['is_it_illegal'])
                ->setCellValue('L' . $myrow, $value['orderdetails']['total_deduction'])
                ->setCellValue('M' . $myrow, $value['orderdetails']['total_fine'])
                ->setCellValue('N' . $myrow, $value['orderdetails']['update_violation_time'])
                ->setCellValue('O' . $myrow, $value['orderdetails']['annual_inspection_time'])
                ->setCellValue('P' . $myrow, $value['orderdetails']['traffic_force_insurance_time']);
            $objPHPExcel->getActiveSheet()->getRowDimension('' . $myrow)->setRowHeight(20);/*设置行高 不能批量的设置 这种感觉 if（has（蛋）！=0）{疼();}*/
            $myrow++;
            $mynum++;
        }

        $mynumdata = $myrow - 1; //获取主要数据结束的行号
        $objPHPExcel->setActiveSheetIndex(0)->getstyle('A3:P' . $mynumdata)->getAlignment()->setHorizontal(\PHPExcel_style_Alignment::HORIZONTAL_CENTER);/*设置格式 水平居中*/
        /*设置数据的边框 手册上写的方法只显示竖线 非常坑爹 所以采用网上搜来的方法*/
        $style_array = array(
            'borders' => array(
                'allborders' => array(
                    'style' => \PHPExcel_Style_Border::BORDER_THIN
                )
            ));
//
//        $objPHPExcel->getActiveSheet()->getStyle('A3:P' . $mynumdata)->applyFromArray($style_array);
//        /*设置数据的格式*/
//        $objPHPExcel->getActiveSheet()->getRowDimension('' . $myrow)->setRowHeight(20);/*设置行高*/
//        $objPHPExcel->getActiveSheet()->mergeCells('A'.$myrow.':P'.$myrow);//合并下一行的单元格
//        $objPHPExcel->setActiveSheetIndex(0) ->setCellValue('A' . $myrow,'供应单位：'.$name);
//        $myrow++; $objPHPExcel->getActiveSheet()->getRowDimension('' . $myrow)->setRowHeight(20);/*设置行高*/
//        $objPHPExcel->getActiveSheet()->mergeCells('A'.$myrow.':C'.$myrow);//合并下一行的单元格
//        $objPHPExcel->setActiveSheetIndex(0) ->setCellValue('A' . $myrow,'收料员：');
//        $objPHPExcel->getActiveSheet()->mergeCells('D'.$myrow.':J'.$myrow);//合并下一行的单元格
//        $objPHPExcel->setActiveSheetIndex(0) ->setCellValue('D' . $myrow,'复核：');
//        $objPHPExcel->getActiveSheet()->mergeCells('K'.$myrow.':P'.$myrow);//合并下一行的单元格
//        $objPHPExcel->setActiveSheetIndex(0) ->setCellValue('K' . $myrow,'确认签字：');
//        $myrow++; $objPHPExcel->getActiveSheet()->getRowDimension('' . $myrow)->setRowHeight(20);/*设置行高*/
//        $objPHPExcel->getActiveSheet()->mergeCells('I'.$myrow.':K'.$myrow);//合并下一行的单元格
//        $objPHPExcel->setActiveSheetIndex(0) ->setCellValue('I' . $myrow,'日期：');
//        $objPHPExcel->getActiveSheet()->getStyle('A1:P' . $myrow)->getAlignment()->setVertical(\PHPExcel_Style_Alignment::VERTICAL_CENTER);/*垂直居中*/
        //关键数据结束


        //设置宽width 由于自适应宽度对中文的支持是个BUG因此坑爹的手动设置了每一列的宽度 这种感觉 if（has（蛋）！=0）{碎();}
        $objPHPExcel->getActiveSheet()->getColumnDimension('A')->setWidth(5);
        $objPHPExcel->getActiveSheet()->getColumnDimension('B')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('C')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('D')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('E')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('F')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('G')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('H')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('I')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('J')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('K')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('L')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('M')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('N')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('O')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getColumnDimension('P')->setWidth(15);
        $objPHPExcel->getActiveSheet()->getStyle('A3:P' . $myrow)->getAlignment()->setWrapText(true);//设置单元格允许自动换行
        /*设置表相关的信息*/
        $objPHPExcel->getActiveSheet()->setTitle('第一张表'); //活动表的名称
        $objPHPExcel->setActiveSheetIndex(0);//设置第一张表为活动表
        //纸张方向和大小 为A4横向
        $objPHPExcel->getActiveSheet()->getPageSetup()->setOrientation(\PHPExcel_Worksheet_PageSetup::ORIENTATION_LANDSCAPE);
        $objPHPExcel->getActiveSheet()->getPageSetup()->setPaperSize(\PHPExcel_Worksheet_PageSetup::PAPERSIZE_A4);
        //浏览器交互 导出
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="客户信息数据.xlsx"');
        header('Cache-Control: max-age=0');
        // If you're serving to IE 9, then the following may be needed
        header('Cache-Control: max-age=1');

        // If you're serving to IE over SSL, then the following may be needed
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // always modified
        header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
        header('Pragma: public'); // HTTP/1.0
        $objWriter = \PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
        $objWriter->save('php://output');
        exit;

    }

    /**单个分配给客服
     * @param null $ids
     * @return string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function allocation($ids = NULL)
    {
        $serviceList = $this->getService();
        // pr($serviceList);
        // die;
        $this->view->assign([
            'serviceList' => $serviceList
        ]);

        if ($this->request->isPost()) {

            $params = $this->request->post('row/a');
            $result = $this->model->save($params, function ($query) use ($ids) {
                $query->where('id', $ids);
            });
            if ($result) {

                $this->success();

            } else {
                $this->error();
            }
        }

        return $this->view->fetch();
    }


    /**批量分配给客服
     * @param string $ids
     * @return string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function batch($ids = '')
    {
        $serviceList = $this->getService();

        $this->view->assign([
            'serviceList' => $serviceList
        ]);

        if ($this->request->isPost()) {

            $params = $this->request->post('row/a');
            $result = $this->model->save($params, function ($query) use ($ids) {
                $query->where('id', 'in', $ids);
            });
            if ($result) {

                $this->success();

            } else {

                $this->error();
            }
        }
        return $this->view->fetch();
    }

    /**
     * 获取所有的客服
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getService()
    {
        $service = collection(Admin::field('id,nickname,rule_message')->where(function ($query) {
            $query->where([
                'rule_message' => 'message10',
                'status' => 'normal'
            ]);
        })->select())->toArray();

        $serviceList = array();
        foreach ($service as $k => $v) {

            $serviceList[] = ['nickname' => $v['nickname'], 'id' => $v['id']];

        }

        return $serviceList;
    }


    public function export()
    {
        $this->model = new Order();
        if ($this->request->isPost()) {
            set_time_limit(0);
            $search = $this->request->post('search');
            $ids = $this->request->post('ids');
            $filter = $this->request->post('filter');
            $op = $this->request->post('op');
            $columns = $this->request->post('columns');

            $excel = new \PHPExcel();

            $excel->getProperties()
                ->setCreator("FastAdmin")
                ->setLastModifiedBy("FastAdmin")
                ->setTitle("客户信息")
                ->setSubject("Subject");
            $excel->getDefaultStyle()->getFont()->setName('Microsoft Yahei');
            $excel->getDefaultStyle()->getFont()->setSize(12);

            $this->sharedStyle = new \PHPExcel_Style();
            $this->sharedStyle->applyFromArray(
                array(
                    'fill' => array(
                        'type' => \PHPExcel_Style_Fill::FILL_SOLID,
                        'color' => array('rgb' => '000000')
                    ),
                    'font' => array(
                        'color' => array('rgb' => "000000"),
                    ),
                    'alignment' => array(
                        'vertical' => \PHPExcel_Style_Alignment::VERTICAL_CENTER,
                        'horizontal' => \PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                        'indent' => 1
                    ),
                    'borders' => array(
                        'allborders' => array('style' => \PHPExcel_Style_Border::BORDER_THIN),
                    )
                ));

            $worksheet = $excel->setActiveSheetIndex(0);
            $worksheet->setTitle('扣款失败客户');

            $whereIds = $ids == 'all' ? '1=1' : ['id' => ['in', explode(',', $ids)]];
            $this->request->get(['search' => $search, 'ids' => $ids, 'filter' => $filter, 'op' => $op]);
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $whereIds['jyzj_order.id'] = $whereIds['id'];
            unset($whereIds['id']);
//pr($whereIds);die;
//            pr($where);die;
            $line = 1;
            $list = [];

            Db::name('order')
                ->alias('a')
                ->join('admin b', 'a.admin_id = b.id')
                ->join('order_details c', 'a.id = c.order_id', 'LEFT')
                ->field('a.id,a.username,b.nickname,c.file_coding')
//                ->where('monthly_status','has_been_sent')
//                ->where($where)
//                ->where($whereIds)
                ->chunk(100, function ($items) use (&$list, &$line, &$worksheet) {
                    $styleArray = array(
                        'font' => array(

                            'color' => array('color' => '#222'),
                            'size' => 11,
                            'name' => 'Verdana'
                        ));

                    $list = $items = collection($items)->toArray();
                    foreach ($items as $index => $item) {
                        //                        $item['monthly_card_number'] =   ' '.$item['monthly_card_number'];
//                        $item['monthly_phone_number'] =   ' '.$item['monthly_phone_number'];
//                        $item['monthly_data'] = '失败';
//                        unset($item['monthly_data_text']);
                        $line++;
                        $col = 0;

                        foreach ($item as $field => $value) {


                            $worksheet->setCellValueByColumnAndRow($col, $line, $value);

                            $worksheet->getStyleByColumnAndRow($col, $line)->getNumberFormat()->setFormatCode(\PHPExcel_Style_NumberFormat::FORMAT_TEXT);
                            $worksheet->getCellByColumnAndRow($col, $line)->getStyle()->applyFromArray($styleArray);
                            $col++;
                        }
                    }

                });

            $first = array_keys($list[0]);
            foreach ($first as $index => $item) {
                $worksheet->setCellValueByColumnAndRow($index, 1, __($item));
            }

            $excel->createSheet();
            // Redirect output to a client’s web browser (Excel2007)
            $title = date("YmdHis");
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $title . '.xlsx"');
            header('Cache-Control: max-age=0');
            // If you're serving to IE 9, then the following may be needed
            header('Cache-Control: max-age=1');

            // If you're serving to IE over SSL, then the following may be needed
            header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // always modified
            header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
            header('Pragma: public'); // HTTP/1.0

            $objWriter = \PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
            $objWriter->save('php://output');
            return;
        }
    }

    //查看客服反馈信息
    public function feedback($ids = null)
    {
        $detail = $this->model->field('id,username,phone')
            ->with(['orderdetails' => function ($q) {
                $q->withField('feedback');
            }, 'service' => function ($q) {
                $q->withField('nickname');
            }])->find($ids);

        $feedback = json_decode($detail['orderdetails']['feedback'], true);

        // pr($feedback);
        // die;
        $this->view->assign([
            'detail' => $detail,
            'feedback' => $feedback,
        ]);

        return $this->view->fetch();
    }

    //新增客服反馈记录
    public function saveinfo()
    {

        if ($this->request->isAjax()) {
            $params = $this->request->post();

            $result = OrderDetails::where('order_id', $params['id'])->find()['feedback'];

            if ($result) {

                $feedback = [
                    'message' => $params['text'],
                    'date' => date('Y-m-d H:i:s', time())
                ];

                $res = json_decode($result, true);

                array_push($res, $feedback);

                $feedback = json_encode($res);
            } else {

                $feedback = [[
                    'message' => $params['text'],
                    'date' => date('Y-m-d H:i:s', time())
                ]];

                $feedback = json_encode($feedback);
            }

            $r = OrderDetails::where('order_id', $params['id'])->update(['feedback' => $feedback]);

            if ($r) {
                $this->success('', '', json_decode($feedback));
            } else {
                $this->error();
            }

        }

    }

    //测试方法
    public function ceshi()
    {
        $result = Admin::where('id', 1)->update(['status' => 'hidden']);


    }

    public function update_year()
    {
//        echo date('Y-m-d',OrderDetails::get(1799)->annual_inspection_time);

//        die;

        if ($this->request->isGet()) {
            ini_set('max_execution_time', 0);

            $page = $this->request->get('page');

            $res = OrderDetails
                ::where('licensenumber', 'not in', ['null', ''])
                ->where([
//                    'annual_inspection_time' => '',
                    'id' => ['ELT', 349],
//                'id'=>['in',[1694,1690,1697,1686,1685,1684,1683,1680,1623,1538,1526,1466,1292,
//                    1165,1064,989,898,869,677,665]]
                    'reson_query_fail' => null
                ])
                ->order('id desc')
                ->page($page . ',50')
                ->field('id,licensenumber,annual_inspection_time,reson_query_fail')
//                ->fetchSql(true)
                ->select();
//pr($res);
//die;
//            pr(collection($res)->toArray());
//
//            die;


            $record = [];
            foreach ($res as $k => $v) {


                $data = \fast\Http::get('http://v.juhe.cn/carInfo/querySimple.php', [
                    'number' => $v->licensenumber,
                    'key' => Env::get('juhe.jiancekey'),
                ]);

                $data = json_decode($data, true);

                if ($data['error_code'] == 0) {
                    $v->annual_inspection_time = strtotime($data['result']['jianCheTime']);

                    $v->annual_inspection_status = self::check_state(strtotime($data['result']['jianCheTime']));
                    $v->save();

                    $record[] = ['status' => 'success', 'res' => 'ID:' . $v->id . ',' . $data['reason']];

                    continue;
                }

                $v->annual_inspection_time = $data['reason'];

                $v->annual_inspection_status = 'query_failed';
                $v->save();

                $record[] = ['status' => 'error', 'res' => 'ID:' . $v->id . ',' . $data['reason']];
            }


            pr($record);


        }


    }

    /**
     * 展示二维码图片
     * @param $ids
     * @return string
     * @throws Exception
     * @throws \think\exception\DbException
     */
    public function qrcode($ids)
    {
        $url = $this->request->get('url');

        $this->view->assign([
            'url' => 'https://jyzj.junyiqiche.com' . $url,
            'username' => $this->model->get($ids)->username
        ]);
        return $this->view->fetch();
    }

    public function test()
    {
//        $a = OrderDetails::field('id,annual_inspection_time')
//        ->where([
//            'annual_inspection_time' =>['not in',['当前可请求的次数不足','null','','内部错误，数据异常']],
//            'annual_inspection_status' => 'no_queries'
//        ])->select();

//       $data = Http::get('http://cars.ruyitech.net/api/queries_dev/成都/川AC42R9');

//        $data = Http::get('http://cars.ruyitech.net/api/queries_dev/成都/川AC42R9', [
//        'car_vin' => 'LFV2A11K1J4002371',
//        'engine_no' => 'KD0003'
//        ]);
//        $data = json_decode($data, true);


        $redis = new \Redis;

        $redis->connect('120.78.135.109', '6379');

        $redis->auth('aicheyide');


    }

    public function actionWithHelloJob()
    {

        // 1.当前任务将由哪个类来负责处理。
        //   当轮到该任务时，系统将生成一个该类的实例，并调用其 fire 方法
        $jobHandlerClassName = 'app\admin\job\Hello';

        // 2.当前任务归属的队列名称，如果为新队列，会自动创建
        $jobQueueName = "helloJobQueue";

        // 3.当前任务所需的业务数据 . 不能为 resource 类型，其他类型最终将转化为json形式的字符串
        //   ( jobData 为对象时，存储其public属性的键值对 )
        $jobData = ['ts' => time(), 'bizId' => uniqid(), 'a' => 1];

        // 4.将该任务推送到消息队列，等待对应的消费者去执行
        $isPushed = Queue::push($jobHandlerClassName, $jobData, $jobQueueName);

        // database 驱动时，返回值为 1|false  ;   redis 驱动时，返回值为 随机字符串|false
        if ($isPushed !== false) {
            echo date('Y-m-d H:i:s') . " a new Hello Job is Pushed to the MQ" . "<br>";
        } else {
            echo 'Oops, something went wrong.';
        }
    }


    public function insurance()
    {

        if ($this->request->isAjax()) {

            $params = $this->request->post();

            // pr($params['id']);
            // die;
            $this->model = new \app\admin\model\Order();

            $result = $this->model->field('username,phone,wx_public_user_id,models_name')
                ->with(['orderdetails' => function ($q) use ($ids) {
                    $q->withField('licensenumber,total_deduction,total_fine,violation_details');
                }])->find($params['id']);

            // pr($result['orderdetails']['licensenumber']);
            // die;

            $data = Http::get('http://cars.ruyitech.net/api/queries_dev/成都/' . $result['orderdetails']['licensenumber']);

            // if ($data['status'] == 'success') {

            //     pr($data['data']);
            //     die;
            // }

            pr($data);


        }

    }

    //查看重复数据
    public function repeat()
    {
        OrderDetails::field('id,licensenumber,frame_number,engine_number')
            ->chunk(200, function ($item) {
                foreach ($item as $key => $value) {

                    $licensenumber .= $value['licensenumber'] . ',';
                    $frame_number .= $value['frame_number'] . ',';
                    $engine_number .= $value['engine_number'] . ',';


                }


                foreach ($item as $key => $value) {


                    if ($value['licensenumber'] && substr_count($licensenumber, $value['licensenumber']) >= 2) {

                        OrderDetails::where(['licensenumber' => $value['licensenumber']])->update(['is_repeat' => 1]);

                    }

                    if ($value['frame_number'] && substr_count($frame_number, $value['frame_number']) >= 2) {

                        OrderDetails::where(['frame_number' => $value['frame_number']])->update(['is_repeat' => 1]);


                    }

                    if ($value['engine_number'] && substr_count($engine_number, $value['engine_number']) >= 2) {

                        OrderDetails::where(['engine_number' => $value['engine_number']])->update(['is_repeat' => 1]);

                    }

                }


            });
    }


    //点击表格中客服反馈记录
    public function feedbackMessage()
    {

        if ($this->request->isAjax()) {
            $params = $this->request->post();

            $result = OrderDetails::where('order_id', $params['id'])->find()['feedback'];

            if ($result) {

                $this->success('', '', json_decode($result, true));
            } else {

                $this->error();

            }

        }

    }


}
