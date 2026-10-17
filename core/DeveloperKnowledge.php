<?php

/** Read model and UTC health summaries; no request/response payloads are stored. */
final class DeveloperKnowledge
{
    public const MODULES = [
        'Catalog'=>['description'=>'Published products, variants, taxonomy and versioned reads.', 'classes'=>['StorefrontProductController','CatalogReadService','ProductService','Cache']],
        'Cart'=>['description'=>'Owned carts, canonical quantities and calculated totals.', 'classes'=>['Cart','CartService']],
        'Orders'=>['description'=>'Atomic placement, stock consumption and order history.', 'classes'=>['OrderService','OrderStates']],
        'Inventory'=>['description'=>'Stock movements, reservations and availability.', 'classes'=>['InventoryService']],
        'Identity'=>['description'=>'Customer identity, staff grants and owned addresses.', 'classes'=>['CustomerAccount','Auth','CommerceAccess','AddressService']],
        'Operations'=>['description'=>'Bounded queue workers and durable notifications.', 'classes'=>['Queue','QueueWorker','NotificationJob']],
        'Developer'=>['description'=>'Dedicated console, runtime readings and trace reports.', 'classes'=>['Developer','DeveloperAccess','DeveloperKnowledge','DeveloperTrace']],
        'Framework'=>['description'=>'Method-aware routing and prepared database access.', 'classes'=>['RouteManager','CommerceDatabase','StorefrontController']],
    ];
    public const INSTRUMENTED = ['RouteManager::dispatch','StorefrontProductController::listing','StorefrontProductController::detail',
        'CatalogReadService::listing','CatalogReadService::detail','CatalogReadService::filters','CatalogReadService::read',
        'Cache::get','Cache::put','ProductService::listing','ProductService::detail','ProductService::listProducts',
        'ProductService::hydrate','ProductService::query','CommerceDatabase::query','StorefrontController::jsonValues'];

    public function __construct(private PDO $pdo) {}
    public static function module(string $class): string
    {
        foreach (self::MODULES as $name=>$module) if (in_array($class,$module['classes'],true)) return $name;
        return 'Framework';
    }
    public static function registry(array $routes): array
    {
        $apis = [];
        foreach ($routes as $path=>$definition) {
            $handlers = array_is_list($definition) ? ['GET'=>$definition] : $definition;
            foreach ($handlers as $method=>[$class,$function]) {
                $apis[] = ['id'=>$method.' /'.$path,'route'=>$path,'method'=>$method,'module'=>self::module($class),
                    'symbol'=>$class.'::'.$function,'traced'=>str_starts_with($path,'api/v1/'),
                    'testable'=>$method==='GET' && in_array($path,['api/v1/products','api/v1/products/{slug}'],true)];
            }
        }
        $modules = [];
        foreach (self::MODULES as $name=>$definition) {
            $symbols = [];
            foreach ($definition['classes'] as $class) {
                if (!class_exists($class)) continue;
                $reflection = new ReflectionClass($class);
                foreach ($reflection->getMethods() as $method) {
                    if ($method->getDeclaringClass()->getName() !== $class || str_starts_with($method->name,'__')) continue;
                    $symbol = $class.'::'.$method->name;
                    $symbols[] = ['symbol'=>$symbol,'file'=>str_replace(dirname(__DIR__).'/','',$method->getFileName()),
                        'line'=>$method->getStartLine(),'visibility'=>$method->isPublic()?'public':($method->isProtected()?'protected':'private'),
                        'instrumented'=>in_array($symbol,self::INSTRUMENTED,true)];
                }
            }
            $modules[] = ['name'=>$name,'description'=>$definition['description'],'symbols'=>$symbols];
        }
        return compact('apis','modules');
    }

    public function record(array $trace): void
    {
        $statement = $this->pdo->prepare('INSERT INTO developer_traces (id,route,method,module,started_at,duration_us,status,spans,truncated) VALUES (?,?,?,?,?,?,?,?,?)');
        $statement->execute([$trace['id'],$trace['route'],$trace['method'],$trace['module'],$trace['started_at'],
            $trace['duration_us'],$trace['status'],json_encode($trace['spans'],JSON_THROW_ON_ERROR),(int)$trace['truncated']]);
    }
    public function recent(?string $id = null): array
    {
        if ($id !== null && !preg_match('/\A[a-f0-9]{32}\z/',$id)) throw new InvalidArgumentException('Invalid trace ID.');
        $statement = $this->pdo->prepare('SELECT * FROM developer_traces'.($id===null?'':' WHERE id=?').' ORDER BY started_at DESC,id DESC LIMIT 40');
        $statement->execute($id===null?[]:[$id]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { $row['spans']=json_decode($row['spans'],true,512,JSON_THROW_ON_ERROR); $row['truncated']=(bool)$row['truncated']; }
        return $rows;
    }
    public static function day(string $day): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d',$day,new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d')!==$day || $day>gmdate('Y-m-d')) throw new InvalidArgumentException('Use a valid UTC date, today or earlier.');
        return $date->getTimestamp();
    }
    private static function health(array $row): array
    {
        foreach (['requests','failures','client_errors','slow','max_us'] as $field) $row[$field]=(int)$row[$field];
        $row['avg_us']=(int)round((float)$row['avg_us']);
        $row['health']=$row['failures']>0?'failing':($row['slow']>0?'degraded':($row['requests']>0?'healthy':'unknown'));
        return $row;
    }
    public function report(string $day, bool $snapshot = true): array
    {
        $start = self::day($day);
        if ($snapshot && $day<gmdate('Y-m-d')) {
            $query=$this->pdo->prepare('SELECT report FROM developer_daily_reports WHERE day=?'); $query->execute([$day]);
            $saved=$query->fetchColumn(); if ($saved!==false) return json_decode($saved,true,512,JSON_THROW_ON_ERROR);
        }
        $aggregates = "COUNT(*) AS requests,SUM(CASE WHEN status>=500 THEN 1 ELSE 0 END) AS failures,
            SUM(CASE WHEN status>=400 AND status<500 THEN 1 ELSE 0 END) AS client_errors,
            SUM(CASE WHEN duration_us>=1000000 THEN 1 ELSE 0 END) AS slow,AVG(duration_us) AS avg_us,MAX(duration_us) AS max_us";
        $apis=$this->pdo->prepare("SELECT route,method,module,$aggregates FROM developer_traces WHERE started_at>=? AND started_at<? GROUP BY route,method,module ORDER BY module,route,method");
        $apis->execute([$start,$start+86400]); $apiRows=array_map([self::class,'health'],$apis->fetchAll(PDO::FETCH_ASSOC));
        $modules=$this->pdo->prepare("SELECT module,$aggregates FROM developer_traces WHERE started_at>=? AND started_at<? GROUP BY module ORDER BY module");
        $modules->execute([$start,$start+86400]); $moduleRows=array_map([self::class,'health'],$modules->fetchAll(PDO::FETCH_ASSOC));
        $observed=array_column($moduleRows,'module');
        foreach (array_keys(self::MODULES) as $module) if (!in_array($module,$observed,true))
            $moduleRows[]=self::health(['module'=>$module,'requests'=>0,'failures'=>0,'client_errors'=>0,'slow'=>0,'avg_us'=>0,'max_us'=>0]);
        return ['day'=>$day,'timezone'=>'UTC','generated_at'=>time(),'snapshot'=>false,
            'coverage'=>'Observed registered API requests only; function spans are explicitly instrumented. No observations means unknown, not healthy.',
            'policy'=>['failure'=>'HTTP 5xx','slow_us'=>1000000,'client_errors'=>'HTTP 4xx counted separately; not routine health failures'],
            'apis'=>$apiRows,'modules'=>$moduleRows];
    }
    public function daily(string $day): array
    {
        if ($day>=gmdate('Y-m-d')) throw new InvalidArgumentException('Daily snapshots require a completed UTC day.');
        if ($this->pdo->inTransaction()) throw new LogicException('Daily reporting requires its own connection state.');
        $report=$this->report($day,false);$report['snapshot']=true;
        $sql=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ? 'INSERT INTO developer_daily_reports (day,generated_at,report) VALUES (?,?,?) ON DUPLICATE KEY UPDATE generated_at=VALUES(generated_at),report=VALUES(report)'
            : 'INSERT INTO developer_daily_reports (day,generated_at,report) VALUES (?,?,?) ON CONFLICT(day) DO UPDATE SET generated_at=excluded.generated_at,report=excluded.report';
        $this->pdo->prepare($sql)->execute([$day,$report['generated_at'],json_encode($report,JSON_THROW_ON_ERROR)]);
        // Bounded retention; overlapping cron invocations may delete the same old IDs safely.
        $old=$this->pdo->prepare('SELECT id FROM developer_traces WHERE started_at<? ORDER BY started_at LIMIT 1000');
        $old->execute([time()-30*86400]);$ids=$old->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) $this->pdo->prepare('DELETE FROM developer_traces WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')')->execute($ids);
        return $report;
    }
}
