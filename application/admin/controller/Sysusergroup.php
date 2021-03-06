<?php
namespace app\admin\controller;
use think\Db;
use app\admin\model\SysUserGroup as SysUserGroupModel;
use app\admin\model\SysUser as SysUserModel;
use app\admin\model\Log as LogModel;
use think\Exception;

/**
 * 管理员权限组控制器
 * Class Sysusergroup
 * @package app\admin\controller
 */
class Sysusergroup extends Base
{
    public function _initialize()
    {
        parent::_initialize();
        $this->groupModel = model('SysUserGroup');
    }

    //展示页面
    public function index(){
        $groupModel = $this->groupModel;
        $where = [];
        //权限组名关键词搜索
        $keyword = input('keyword','');
        if( $keyword == '' ){
            $where['group_name'] = ['like',"%{$keyword}%"];
        }
        $list = Db::table($groupModel->getTable())
            ->where($where)
            ->order('status','desc')
            ->paginate(2);

        if( !empty($list) ){
            foreach( $list as $k => $v ){
                $v['statusType'] = SysUserGroupModel::$map_status[$v['status']];
                $list[$k] = $v;
            }
        }

        $this->assign('list',$list);
        $page = $list->render();
        $this->assign('page',$page);
        return $this->fetch();
    }

    //获取权限组列表
    public function get_group_list(){
        $groupModel = $this->groupModel;
        $where = [];
        $page_param = [];
        //权限组名关键词搜索
        $keyword = input('post.keyword','');
        if( $keyword != '' ){
            $where['group_name'] = ['like',"%{$keyword}%"];
            $page_param['keyword'] = $keyword;
        }
        $page = intval(input('page',1));;
        $list = Db::table($groupModel->getTable())
            ->where($where)
            ->order('group_id','asc')
            ->paginate(15,false,['page'=>$page,'path'=>url('admin/sysusergroup/index',$page_param)]);

        if( !empty($list) ){
            foreach( $list as $k => $v ){
                $v['statusType'] = SysUserGroupModel::$map_status[$v['status']];
                $list[$k] = $v;
            }
        }
        $info['list'] = $list;
        $this->assign('list',$list);
        $page = $list->render();
        $info['page'] = empty($page) ? '' : $page;
        returnJson(true,'success',$info);
    }

    /**
     * 获取所有权限组配置表内容
     */
    public function get_power_list(){
        $group_id = input('post.group_id',0);
        $powerArr = [];
        if( $group_id > 0 ){
            $info = Db::table($this->groupModel->getTable())
                ->field('value')
                ->where('group_id',$group_id)
                ->find();

            if( empty($info) ){
                returnJson(false,'参数错误');
            }
            $powerArr = explode(',',$info['value']);
        }
        $menuList = $this->getMenuList();
        $menuList = $this->get_group_set($menuList,$powerArr);
        returnJson(true,'success',$menuList);
    }

    /**
     * 配置参数集
     * @param array $menuList       菜单权限内容
     * @param array $powerArr       已设置的权限内容 checked = 1 为已设置
     * @return array
     */
    private function get_group_set($menuList=[],$powerArr=[]){
        if( !empty($menuList) ) {
            foreach ( $menuList as $key => $value ) {
                $value['checked'] = false;
                if( in_array($value['power'],$powerArr) ){
                    $value['checked'] = true;
                }
                if(!empty($value['child'])){
                    $value['child'] = $this->get_group_set($value['child'],$powerArr);
                }
                $menuList[$key] = $value;
            }
        }
        return $menuList;
    }

    //添加页面
    public function add(){
        return $this->fetch();
    }

    /**
     * 创建权限组
     */
    public function create_group(){
        //配置参数信息
        $data = input('post.');
        try{
            if( empty($data['group_name']) ){
                throw new Exception('参数错误');
            }
            $t = time();
            //检查权限组名是否重复
            $group_name = trim($data['group_name']);
            $checkInfo = Db::table($this->groupModel->getTable())
                ->where('group_name',$group_name)
                ->find();

            if( !empty($checkInfo) )
                throw new Exception('权限组已存在');

            $power_value = 'home';
            $group_power = empty($data['group_power']) ? [] : $data['group_power'];
            if( !empty($group_power) && is_array($group_power) ){
                $power_value = implode(',',$group_power);
            }
            $insertData = array(
                'group_name'    => $group_name,
                'value'         => $power_value,
                'add_time'      => $t,
                'edit_time'     => $t,
            );
            Db::startTrans();
            if( Db::table($this->groupModel->getTable())->insert($insertData) === false ){
                throw new Exception('网络错误，操作失败');
            }

            //添加日志
            $logModel = model('Log');
            if( $logModel->note(LogModel::INSERT,'添加权限组：'.$insertData['group_name']) === false )
                throw new Exception('网络错误，操作失败');

            Db::commit();
        }catch( Exception $e ){
            Db::rollback();
            returnJson(false,$e->getMessage());
        }
        returnJson(true,'添加成功');
    }

    /**
     * 更新权限组页
     */
    public function edit(){
        $id = intval(input('param.id',0));
        if( $id <= 0 ){
            noPermission();
        }
        $info = Db::table($this->groupModel->getTable())
            ->where('group_id',$id)
            ->find();

        if( empty($info) )
            noPermission();

        $this->assign('info',$info);
        return $this->fetch();
    }

    /**
     * 更新权限组信息
     */
    public function update_group(){
        //配置参数信息
        $data = input('post.');
        try{
            if( empty($data['group_id']) )
                throw new Exception('参数错误');

            $group_id = intval($data['group_id']);
            $info = Db::table($this->groupModel->getTable())
                ->where('group_id',$group_id)
                ->find();

            if( empty($info) )
                throw new Exception('权限组信息异常');

            if( empty($data['group_name']) )
                throw new Exception('权限组名不能为空');

            $group_name = trim($data['group_name']);
            $t = time();
            //检查权限组名是否重复
            $check_where['group_name'] = ['=',$group_name];
            $check_where['group_id'] = ['<>',$info['group_id']];
            $checkInfo = Db::table($this->groupModel->getTable())
                ->where($check_where)
                ->find();

            if( !empty($checkInfo) )
                throw new Exception('权限组名已存在');

            $power_value = 'home';
            $group_power = empty($data['group_power']) ? [] : $data['group_power'];
            if( !empty($group_power) && is_array($group_power) ){
                $power_value = implode(',',$group_power);
            }

            $insertData = array(
                'group_name' => $group_name,
                'value' => $power_value,
                'edit_time'  => $t,
            );
            Db::startTrans();
            $updateState = Db::table($this->groupModel->getTable())->where('group_id',$info['group_id'])->update($insertData);
            if( empty($updateState) )
                throw new Exception('网络错误，操作失败x1');

            //日志记录
            $logModel = model('Log');
            if( $logModel->note(LogModel::UPDATES,'编辑权限组：'.$info['group_name']) === false )
                throw new Exception('网络错误，操作失败x2');

            Db::commit();
        }catch( Exception $e ){
            Db::rollback();
            returnJson(false,$e->getMessage());
        }
        returnJson(true,'保存成功');
    }

    /**
     * 禁用权限组
     * @throws Exception
     * @throws \think\exception\PDOException
     */
    public function disable_group(){
        $data = input('post.');
        try{
            if( empty($data['group_id']) || empty($data['key']) )
                throw new Exception('参数错误');

            $group_id = intval($data['group_id']);
            $info = Db::table($this->groupModel->getTable())
                ->where('group_id',$group_id)
                ->find();

            if( empty($info) )
                throw new Exception('参数错误');

            $key = trim($data['key']);
            if( $key != __COMPANYKEY__ )
                throw new Exception('秘钥错误');

            Db::startTrans();
            $updateData['status'] = $info['status'] ? SysUserGroupModel::DISABLE_STATUS : SysUserGroupModel::NORMAL_STATUS;
            $conMsg = SysUserGroupModel::$map_status[$updateData['status']];
            $updateState = Db::table($this->groupModel->getTable())->where('group_id',$group_id)->update($updateData);
            if( empty($updateState) )
                throw new Exception('网络错误，操作失败');

                //日志记录
            $logModel = model('Log');
            if( $logModel->note(LogModel::UPDATES,"设置权限组：{$info['group_name']} 为 {$conMsg} 状态") === false )
                throw new Exception('网络错误，操作失败');

            Db::commit();
            returnJson(true,'操作成功');
        }catch( Exception $e ){
            Db::rollback();
            returnJson(false,$e->getMessage());
        }
    }

    //初始化超级管理员权限组
    public function default_super_group()
    {
        $key = Request()->post('key');
        try {
            if ($key == '')
                throw new Exception('非法操作');

            if ($key != __COMPANYKEY__)
                throw new Exception('秘钥错误');

            $t = time();
            //检查超级管理员权限组是否存在
            $info = Db::table($this->groupModel->getTable())->where('status', SysUserGroupModel::SUPER_STATUS)->find();
            $menuList = $this->getMenuList();
            $poser_value = $this->groupModel->filterPowerData($menuList);
            $poser_value = implode(',', $poser_value);
            Db::startTrans();
            if (empty($info)) {
                $data = array(
                    'group_name' => '超级管理员',
                    'value' => $poser_value,
                    'status' => SysUserGroupModel::SUPER_STATUS,
                    'add_time' => $t,
                    'edit_time' => $t
                );
                if (Db::table($this->groupModel->getTable())->insert($data) === false)
                    throw new Exception('初始化失败');

            } else {
                $data = array(
                    'value' => $poser_value,
                    'edit_time' => $t
                );
                $updateState = Db::table($this->groupModel->getTable())->where('group_id', $info['group_id'])->update($data);
                if (empty($updateState))
                    throw new Exception('初始化失败');
            }
            //日志记录
            $logModel = model('Log');
            if( $logModel->note(LogModel::UPDATES,"初始化超级管理员权限配置") === false ) {
                throw new Exception('保存失败');
            }
            Db::commit();
        }catch( Exception $e ){
            Db::rollback();
            returnJson(false, $e->getMessage());
        }
        returnJson(true, '初始化成功');
    }


}
