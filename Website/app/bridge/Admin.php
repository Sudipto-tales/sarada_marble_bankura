<?php

/** Commerce staff UI. Developer sessions/grants are deliberately independent. */
class Admin extends StorefrontController
{
    private array $errors=[];
    private ?array $values=null;
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
        $this->handle(function():void{
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
                    $this->redirect('admin');return;
                }catch(RequestRejected $e){http_response_code($e->status);$errors['form']=$e->getMessage();}
            }
            $this->respond('app/page/admin/login.php',compact('errors','email'));
        });
    }
    public function logout(): void
    {
        $this->handle(function():void{RequestSecurity::requireMutation();Auth::logout();$this->redirect('admin/login');});
    }
    public function index(): void
    {
        $this->handle(fn()=> $this->render('overview',['cards'=>(new AdminReadService($this->pdo))->overview($this->actor())]));
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
                $service=new ProductService($this->pdo);$method=$module==='categories'?'saveCategory':'saveBrand';
                $service->$method($actor['id'],$input,CommerceValues::key($_POST['operation_key']??null));return 'admin/'.$module;
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
                (new OrderService($this->pdo))->transition($actor['id'],$id,CommerceValues::text($_POST['target']??null,20,'target'),CommerceValues::integer($_POST['revision']??null,1,PHP_INT_MAX,'revision'),CommerceValues::key($_POST['operation_key']??null),CommerceValues::text($_POST['reason']??null,190,'reason'));
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
    public function settings(): void
    {
        $this->handle(function():void{CommerceAccess::requirePermission('admin.settings.view');$this->render('settings',['policy'=>OrderService::policy(),'payments'=>OrderStates::paymentModes(),'signup'=>Auth::registrationAvailable()]);});
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

}
