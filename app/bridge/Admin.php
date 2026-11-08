<?php

/** Commerce staff UI. Developer sessions/grants are deliberately independent. */
class Admin extends StorefrontController
{
    private const ELEVATION_IDLE=1800;
    private array $errors=[];
    private ?array $values=null;
    /** Console authority is per-session and separate from the shopping session: it exists only after a
        deliberate /admin/login, never from a storefront login or a remembered cookie, and lapses when idle. */
    protected function handle(callable $action,bool $api=false): void
    {
        parent::handle(function()use($action,$api):void{
            if(!$this->elevated()){
                if($api)throw new RequestRejected(401,'unauthenticated','Staff sign-in is required.');
                $this->redirect('admin/login');return;
            }
            $action();
        },$api);
    }
    private function elevated(): bool
    {
        RequestSecurity::session();
        $actor=CommerceAccess::actor();
        // Non-staff get their existing answers (401 anonymous, 403 customer) from the guards below.
        if(!$actor||!array_filter($actor['permissions'],static fn($p)=>str_starts_with($p,'admin.')))return true;
        if(($_SESSION['admin_id']??null)===(int)$actor['id']&&time()-(int)($_SESSION['admin_seen']??0)<=self::ELEVATION_IDLE){
            $_SESSION['admin_seen']=time();return true;
        }
        unset($_SESSION['admin_id'],$_SESSION['admin_seen']);
        return false;
    }
    private function actor(): array
    {
        $actor=CommerceAccess::actor();
        if(!$actor){$this->redirect('admin/login');exit;}
        if(!array_filter($actor['permissions'],static fn($p)=>str_starts_with($p,'admin.')))throw new RequestRejected(403,'forbidden','Staff access is required.');
        return $actor;
    }
    private function render(string $view,array $data=[]): void
    {
        $actor=$this->actor();$errors=$this->errors;
        RequestSecurity::session();$notice=$_SESSION['admin_notice']??null;unset($_SESSION['admin_notice']);
        $this->respond('app/page/admin/'.$view.'.php',array_merge(compact('actor','errors','notice'),$data));
    }
    private function submitted(callable $save,callable $retry): void
    {
        RequestSecurity::requireMutation();
        try{$path=$save();$_SESSION['admin_notice']='Changes saved successfully.';$this->redirect($path);}
        catch(RequestRejected $e){
            if(!in_array($e->status,[422,409,503],true))throw $e;
            http_response_code($e->status);$this->errors['form']=$e->getMessage();
            if(preg_match('/^Invalid ([a-z_]+)\.$/',$e->getMessage(),$match))$this->errors[$match[1]]=$e->getMessage();
            $this->values=$_POST;$retry();
        }
    }
    public function login(): void
    {
        parent::handle(function():void{
            $errors=[];$email='';RequestSecurity::session();
            if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
                RequestSecurity::requireMutation();
                try{
                    $email=CommerceValues::text($_POST['email']??'',190,'email');$password=$_POST['password']??null;
                    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!is_string($password)||$password==='')CommerceValues::invalid('credentials');
                    $result=Auth::login($email,$password);
                    if(!$result['status'])throw new RequestRejected(($result['code']??'')==='throttled'?429:401,'login_failed',$result['message']);
                    $actor=CommerceAccess::requireActor();
                    if(!array_filter($actor['permissions'],static fn($p)=>str_starts_with($p,'admin.')))throw new RequestRejected(403,'forbidden','This account does not have staff access.');
                    $_SESSION['admin_id']=(int)$actor['id'];$_SESSION['admin_seen']=time();
                    $this->redirect('admin');return;
                }catch(RequestRejected $e){http_response_code($e->status);$errors['form']=$e->getMessage();}
            }
            $this->respond('app/page/admin/login.php',compact('errors','email'));
        });
    }
    public function logout(): void
    {
        parent::handle(function():void{RequestSecurity::requireMutation();Auth::logout();$this->redirect('admin/login');});
    }
    public function index(): void
    {
        $this->handle(function():void{$actor=$this->actor();$read=new AdminReadService($this->pdo);
            $this->render('overview',['cards'=>$read->overview($actor),'attention'=>$read->attention($actor)]);});
    }
    public function products(): void { $this->table('products'); }
    public function table(string $module): void
    {
        $this->handle(fn()=> $this->tablePage($module));
    }
    private function tablePage(string $module): void
    {
        $actor=$this->actor();$definition=AdminReadService::definition($module);
        CommerceAccess::requirePermission($definition[1]);
        $page=CommerceValues::integer($_GET['page']??1,1,50000,'page');
        $input=['start'=>($page-1)*20,'length'=>20,'q'=>$_GET['q']??''];
        $result=(new AdminReadService($this->pdo))->table($actor['id'],$module,$input);
        $taxonomy=null;$values=$this->values;
        if(in_array($module,['categories','brands'],true)&&isset($_GET['edit'])){
            $id=CommerceValues::id($_GET['edit']);$table=$module;
            $taxonomy=CommerceDatabase::query($this->pdo,"SELECT * FROM $table WHERE id=?",[$id])->fetch();
            if(!$taxonomy)throw new RequestRejected(404,'not_found','Record not found.');
        }
        $taxonomies=in_array($module,['categories','brands'],true)?(new ProductService($this->pdo))->taxonomies($actor['id']):null;
        $this->render('table',compact('module','definition','result','page','taxonomy','values','taxonomies'));
    }
    public function data(string $module): void
    {
        $this->handle(function()use($module):void{
            $definition=AdminReadService::definition($module);$actor=CommerceAccess::requirePermission($definition[1]);
            echo json_encode((new AdminReadService($this->pdo))->table($actor['id'],$module,$_GET),JSON_THROW_ON_ERROR);
        },true);
    }
    public function productForm(): void { $this->handle(fn()=> $this->productPage()); }
    private function productPage(): void
    {
        $actor=$this->actor();CommerceAccess::requirePermission('admin.products.view');
        $id=$this->values['id']??$_GET['id']??null;
        $product=$id?(new ProductService($this->pdo))->adminDetail($actor['id'],CommerceValues::id($id)):null;
        if(!$product)CommerceAccess::requirePermission('admin.products.edit');
        $values=$this->values??($product?AdminCatalogForm::display($product):['status'=>'draft','variants'=>[['sku'=>'','sell_unit'=>'sqft','price'=>'','increment'=>'1','enabled'=>1]]]);
        if (!isset($values['details'])) $values['details']=$product?(new ProductDetailService($this->pdo))->get((int)$product['id']):ProductDetailService::validate([]);
        $taxonomies=(new ProductService($this->pdo))->taxonomies($actor['id']);
        $this->render('product',compact('product','values','taxonomies'));
    }
    public function saveProduct(): void
    {
        $this->handle(function():void{
            $actor=CommerceAccess::requirePermission('admin.products.edit');
            $this->submitted(function()use($actor){
                $form=AdminCatalogForm::product($_POST);
                if($form['errors']){$this->errors=$form['errors'];throw new RequestRejected(422,'invalid_input','Check the highlighted fields.');}
                $saved=(new ProductService($this->pdo))->save($actor['id'],$form['data'],CommerceValues::integer($_POST['revision']??null,0,PHP_INT_MAX,'revision'),CommerceValues::key($_POST['operation_key']??null));
                return 'admin/product?id='.$saved['id'];
            },fn()=> $this->productPage());
        });
    }
    public function archiveProduct(): void
    {
        $this->handle(function():void{
            $actor=CommerceAccess::requirePermission('admin.products.edit');RequestSecurity::requireMutation();
            (new ProductService($this->pdo))->archive($actor['id'],CommerceValues::id($_POST['id']??null),CommerceValues::integer($_POST['revision']??null,1,PHP_INT_MAX,'revision'),CommerceValues::key($_POST['operation_key']??null));
            $_SESSION['admin_notice']='Product archived. Order history is preserved.';$this->redirect('admin/products');
        });
    }
    public function saveTaxonomy(string $module): void
    {
        $this->handle(function()use($module):void{
            $actor=CommerceAccess::requirePermission('admin.products.edit');
            if(!in_array($module,['categories','brands'],true))throw new RequestRejected(404,'not_found','Record not found.');
            $this->submitted(function()use($actor,$module){
                $input=$_POST;if(($input['id']??'')==='')unset($input['id']);$input['enabled']=$input['enabled']??0;
                if($module==='categories'&&array_key_exists('tax_rate',$input))$input['tax_bps']=$input['tax_rate']===''?null:CommerceValues::decimal($input['tax_rate'],2,10000,'tax_rate');
                if($module==='categories'&&array_key_exists('advance_rate',$input))$input['advance_bps']=$input['advance_rate']===''?null:CommerceValues::decimal($input['advance_rate'],2,10000,'advance_rate');
                // Brand logos: an upload replaces the stored file, the clear box removes it, neither leaves it as-is.
                unset($input['logo_path']);$images=null;$stored=null;
                if($module==='brands'){
                    $upload=$_FILES['logo']??null;
                    if(is_array($upload)&&($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                        $images=new ProductImageService($this->pdo);
                        $stored=$images->brandLogo($actor['id'],$upload);$input['logo_path']=$stored;
                    } elseif(($_POST['logo_clear']??'')==='1') $input['logo_path']=null;
                }
                $service=new ProductService($this->pdo);$method=$module==='categories'?'saveCategory':'saveBrand';
                try{$service->$method($actor['id'],$input,CommerceValues::key($_POST['operation_key']??null));}
                catch(Throwable $e){if($images&&$stored)$images->discard($stored);throw $e;}
                return 'admin/'.$module;
            },fn()=> $this->tablePage($module));
        });
    }
    public function stockForm(): void { $this->handle(fn()=> $this->stockPage()); }
    private function stockPage(): void
    {
        $actor=$this->actor();$id=CommerceValues::id($this->values['variant_id']??$_GET['id']??null);
        $stock=(new AdminReadService($this->pdo))->stock($actor['id'],$id);$values=$this->values??[];
        $this->render('stock',compact('stock','values'));
    }
    public function adjustStock(): void
    {
        $this->handle(function():void{
            $actor=CommerceAccess::requirePermission('admin.inventory.adjust');
            $this->submitted(function()use($actor){
                $id=CommerceValues::id($_POST['variant_id']??null);$raw=$_POST['quantity']??null;
                if(!is_string($raw))CommerceValues::invalid('quantity');
                $negative=str_starts_with($raw,'-');$delta=CommerceValues::decimal($negative?substr($raw,1):$raw,3,CommerceValues::MAX_QUANTITY,'quantity');
                (new InventoryService($this->pdo))->adjust($actor['id'],$id,$negative?-$delta:$delta,CommerceValues::text($_POST['reason']??null,190,'reason'),CommerceValues::key($_POST['operation_key']??null),CommerceValues::integer($_POST['revision']??null,1,PHP_INT_MAX,'revision'));
                return 'admin/stock?id='.$id;
            },fn()=> $this->stockPage());
        });
    }
    public function orderForm(): void { $this->handle(fn()=> $this->orderPage()); }
    private function orderPage(): void
    {
        $actor=$this->actor();$order=(new OrderService($this->pdo))->staffDetail($actor['id'],CommerceValues::id($this->values['id']??$_GET['id']??null));$values=$this->values??[];
        $this->render('order',compact('order','values'));
    }
    public function transitionOrder(): void
    {
        $this->handle(function():void{
            $actor=CommerceAccess::requirePermission('admin.orders.edit');
            $this->submitted(function()use($actor){
                $id=CommerceValues::id($_POST['id']??null);
                (new OrderService($this->pdo))->transition($actor['id'],$id,CommerceValues::text($_POST['target']??null,32,'target'),CommerceValues::integer($_POST['revision']??null,1,PHP_INT_MAX,'revision'),CommerceValues::key($_POST['operation_key']??null),CommerceValues::text($_POST['reason']??null,190,'reason'));
                return 'admin/order?id='.$id;
            },fn()=> $this->orderPage());
        });
    }
    public function image(): void
    {
        $this->handle(function():void{
            $actor=CommerceAccess::requirePermission('admin.products.edit');
            $this->submitted(function()use($actor){
                $id=CommerceValues::id($_POST['id']??null);$key=CommerceValues::key($_POST['operation_key']??null);
                $service=new ProductImageService($this->pdo);
                if(($_POST['action']??'')==='remove')$service->remove($actor['id'],$id,CommerceValues::id($_POST['image_id']??null),$key);
                else $service->upload($actor['id'],$id,$_FILES['image']??[],$_POST,$key);
                return 'admin/product?id='.$id;
            },function():void{$this->values=null;$this->productPage();});
        });
    }
    public function customerForm(): void { $this->handle(fn()=> $this->customerPage()); }
    private function customerPage(): void
    {
        $actor=$this->actor();CommerceAccess::requirePermission('admin.customers.view');
        $customer=null;$devices=[];
        if(isset($_GET['id'])){
            $id=CommerceValues::id($_GET['id']);
            // Staff identities are managed elsewhere; this screen is customer-only.
            $customer=CommerceDatabase::query($this->pdo,"SELECT id,name,email,status,email_verify FROM users_tbl WHERE id=?
                AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id
                JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=users_tbl.id AND p.permission_key LIKE 'admin.%')",[$id])->fetch();
            if(!$customer)throw new RequestRejected(404,'not_found','Customer not found.');
            $devices=CustomerOtp::deviceListing((int)$customer['id']);
        }
        $this->render('customer',compact('customer','devices'));
    }
    public function revokeDevices(): void
    {
        $this->handle(function():void{
            $actor=CommerceAccess::requirePermission('admin.customers.view');RequestSecurity::requireMutation();
            $id=CommerceValues::id($_POST['id']??null);$key=CommerceValues::key($_POST['operation_key']??null);
            CommerceDatabase::transaction($this->pdo,function()use($actor,$id,$key):void{
                CustomerOtp::revokeAll($id);
                StaffAudit::record($this->pdo,$actor['id'],'customer.devices.revoke','user',$id,$key,[]);
            });
            $_SESSION['admin_notice']='Trusted devices revoked. The customer verifies again on next sign-in.';
            $this->redirect('admin/customer?id='.$id);
        });
    }
    public function settings(): void
    {
        $this->handle(fn()=> $this->settingsPage());
    }
    private function settingsPage():void
    {
        CommerceAccess::requirePermission('admin.settings.view');
        $this->render('settings',['policy'=>OrderService::policy(),'payments'=>OrderStates::paymentModes(),'signup'=>Auth::registrationAvailable(),
            'settings'=>(new SettingsService($this->pdo))->effective(),'otp'=>OtpChannels::adminView($this->pdo),'values'=>$this->values]);
    }
    public function saveSettings():void
    {
        $this->handle(function():void {
            $actor=CommerceAccess::requirePermission('admin.settings.edit');
            $this->submitted(function()use($actor):string {
                (new SettingsService($this->pdo))->save($actor['id'],$_POST,CommerceValues::key($_POST['operation_key']??null));return 'admin/settings';
            },fn()=> $this->settingsPage());
        });
    }
    public function saveOtp():void
    {
        $this->handle(function():void {
            $actor=CommerceAccess::requirePermission('admin.settings.edit');
            $this->submitted(function()use($actor):string {
                OtpChannels::save($this->pdo,$actor['id'],$_POST,CommerceValues::key($_POST['operation_key']??null));return 'admin/settings';
            },fn()=> $this->settingsPage());
        });
    }
    public function couponForm():void {$this->handle(fn()=> $this->couponPage());}
    private function couponPage():void
    {
        $actor=CommerceAccess::requirePermission('admin.pricing.edit');$id=$this->values['id']??$_GET['id']??null;
        $coupon=$id?(new CouponService($this->pdo))->detail($actor['id'],CommerceValues::id($id)):null;
        $values=$this->values;
        if($values===null&&$coupon){
            $values=$coupon;$values['value']=AdminReadService::decimal($coupon['value'],2);
            $values['min_order']=AdminReadService::decimal($coupon['min_order_minor'],2);
            $values['max_discount']=$coupon['max_discount_minor']===null?'':AdminReadService::decimal($coupon['max_discount_minor'],2);
            foreach(['starts_at','ends_at'] as $field)$values[$field]=$coupon[$field]===null?'':gmdate('Y-m-d\TH:i',(int)$coupon[$field]);
        }
        $this->render('coupon',compact('coupon','values'));
    }
    public function saveCoupon():void
    {
        $this->handle(function():void {
            $actor=CommerceAccess::requirePermission('admin.pricing.edit');
            $this->submitted(function()use($actor):string {
                $input=$_POST;$kind=CommerceValues::text($input['kind']??'',8,'kind');
                $input['value']=CommerceValues::decimal($input['value']??'',2,$kind==='percent'?10000:2147483647,'value');
                $input['min_order_minor']=CommerceValues::decimal($input['min_order']??'0',2,CommerceValues::MAX_MONEY,'min_order');
                $input['max_discount_minor']=($input['max_discount']??'')===''?null:CommerceValues::decimal($input['max_discount'],2,CommerceValues::MAX_MONEY,'max_discount');
                foreach(['starts_at','ends_at'] as $field){
                    if(($input[$field]??'')===''){$input[$field]=null;continue;}
                    $value=CommerceValues::text($input[$field],16,$field);$date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('UTC'));
                    if(!$date||$date->format('Y-m-d\TH:i')!==$value)CommerceValues::invalid($field);$input[$field]=$date->getTimestamp();
                }
                $input['enabled']=$input['enabled']??0;
                $id=(new CouponService($this->pdo))->save($actor['id'],$input,CommerceValues::key($_POST['operation_key']??null));return 'admin/coupon?id='.$id;
            },fn()=> $this->couponPage());
        });
    }
    public function archiveCoupon():void
    {
        $this->handle(function():void {
            $actor=CommerceAccess::requirePermission('admin.pricing.edit');RequestSecurity::requireMutation();
            (new CouponService($this->pdo))->archive($actor['id'],CommerceValues::id($_POST['id']??null),CommerceValues::key($_POST['operation_key']??null));
            $this->redirect('admin/coupons');
        });
    }
    public function reports():void
    {
        $this->handle(function():void {
            $actor=CommerceAccess::requirePermission('admin.analytics.view');
            $from=CommerceValues::text($_GET['from']??gmdate('Y-m-d',time()-29*86400),10,'from');$to=CommerceValues::text($_GET['to']??gmdate('Y-m-d'),10,'to');
            $report=(new AdminReadService($this->pdo))->reports($actor['id'],$from,$to);
            if(($_GET['format']??'')==='csv'){$this->reportCsv($report);return;}
            $this->render('reports',compact('report'));
        });
    }
    private function reportCsv(array $report):void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sales-report-'.$report['from'].'-to-'.$report['to'].'.csv"');
        $out=fopen('php://output','w');
        fputcsv($out,['Sales report',$report['from'].' to '.$report['to'].' (UTC)']);
        fputcsv($out,[]);fputcsv($out,['Summary','']);
        fputcsv($out,['Paid orders',$report['sales']['order_count']]);
        fputcsv($out,['Paid revenue (INR)',AdminReadService::decimal($report['sales']['revenue_minor'],2)]);
        fputcsv($out,[]);fputcsv($out,['Orders by status','Orders','Value (INR)']);
        foreach($report['statuses'] as $row)fputcsv($out,[$row['status'],$row['order_count'],AdminReadService::decimal($row['value_minor'],2)]);
        fputcsv($out,[]);fputcsv($out,['Top products (paid)','Units','Revenue (INR)','Orders']);
        foreach($report['topProducts'] as $row)fputcsv($out,[$row['name'],self::quantity($row['qty_milli']),AdminReadService::decimal($row['revenue_minor'],2),$row['order_count']]);
        fclose($out);
    }
    public function reviews(): void
    {
        $this->handle(function():void {
            $actor=$this->actor();CommerceAccess::requirePermission('admin.products.view');$service=new ReviewService($this->pdo);
            if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
                RequestSecurity::requireMutation();
                $service->moderate($actor['id'],CommerceValues::id($_POST['id']??null),CommerceValues::text($_POST['status']??'',16,'status'),CommerceValues::integer($_POST['revision']??null,1,PHP_INT_MAX,'revision'),CommerceValues::key($_POST['operation_key']??null));
                $this->redirect('admin/product-reviews');return;
            }
            $page=CommerceValues::integer($_GET['page']??1,1,1000,'page');$reviews=$service->pending($actor['id'],$page);
            $this->render('reviews',compact('reviews','page'));
        });
    }
    public function orderBalance(): void
    {
        $this->handle(function():void{
            $actor=CommerceAccess::requirePermission('admin.orders.edit');
            $this->submitted(function()use($actor){
                $id=CommerceValues::id($_POST['id']??null);
                (new OrderService($this->pdo))->recordBalance($actor['id'],$id,CommerceValues::integer($_POST['revision']??null,1,PHP_INT_MAX,'revision'),CommerceValues::key($_POST['operation_key']??null));
                return 'admin/order?id='.$id;
            },fn()=> $this->orderPage());
        });
    }
    public function verifyOrder(): void
    {
        $this->handle(function():void{
            $actor=CommerceAccess::requirePermission('admin.orders.edit');
            $this->submitted(function()use($actor){
                $id=CommerceValues::id($_POST['id']??null);
                (new OrderService($this->pdo))->confirmByCall($actor['id'],$id,CommerceValues::text($_POST['outcome']??null,16,'outcome'),
                    ($_POST['note']??'')===''?null:$_POST['note'],CommerceValues::integer($_POST['revision']??null,1,PHP_INT_MAX,'revision'),CommerceValues::key($_POST['operation_key']??null));
                return 'admin/order?id='.$id;
            },fn()=> $this->orderPage());
        });
    }
    public function delivery(): void { $this->handle(fn()=> $this->deliveryPage()); }
    private function deliveryPage(): void
    {
        $actor=$this->actor();CommerceAccess::requirePermission('admin.settings.edit');
        $service=new DeliveryService($this->pdo);
        $zone=isset($_GET['id'])?$service->zone($actor['id'],CommerceValues::id($_GET['id'])):null;
        $this->render('delivery',['zones'=>$service->zones($actor['id']),'zone'=>$zone,'values'=>$this->values]);
    }
    public function saveZone(): void
    {
        $this->handle(function():void{
            $actor=CommerceAccess::requirePermission('admin.settings.edit');
            $this->submitted(function()use($actor){
                $id=(new DeliveryService($this->pdo))->saveZone($actor['id'],$_POST,CommerceValues::key($_POST['operation_key']??null));
                return 'admin/delivery?id='.$id;
            },fn()=> $this->deliveryPage());
        });
    }
    public function saveZonePincodes(): void
    {
        $this->handle(function():void{
            $actor=CommerceAccess::requirePermission('admin.settings.edit');
            $this->submitted(function()use($actor){
                $id=CommerceValues::id($_POST['zone_id']??null);
                (new DeliveryService($this->pdo))->addPincodes($actor['id'],$id,(string)($_POST['pincodes']??''),CommerceValues::key($_POST['operation_key']??null));
                return 'admin/delivery?id='.$id;
            },fn()=> $this->deliveryPage());
        });
    }
    public function staff(): void { $this->handle(fn()=> $this->staffPage()); }
    private function staffPage(): void
    {
        $actor=$this->actor();CommerceAccess::requirePermission('admin.access.view');
        $service=new StaffAccessService($this->pdo);
        $this->render('staff',['roster'=>$service->roster($actor['id']),'roles'=>StaffAccessService::ROLES,
            'isSuper'=>CommerceAccess::isSuperadmin($actor['id']),'values'=>$this->values]);
    }
    public function saveStaff(): void
    {
        $this->handle(function():void{
            $actor=CommerceAccess::requirePermission('admin.access.edit');
            $this->submitted(function()use($actor){
                (new StaffAccessService($this->pdo))->createStaff($actor['id'],$_POST,CommerceValues::key($_POST['operation_key']??null));
                return 'admin/staff';
            },fn()=> $this->staffPage());
        });
    }
    public function staffRole(): void
    {
        $this->handle(function():void{
            $actor=CommerceAccess::requirePermission('admin.access.edit');
            $this->submitted(function()use($actor){
                (new StaffAccessService($this->pdo))->setRole($actor['id'],CommerceValues::id($_POST['user_id']??null),CommerceValues::text($_POST['role']??'',40,'role'),CommerceValues::key($_POST['operation_key']??null));
                return 'admin/staff';
            },fn()=> $this->staffPage());
        });
    }

}
