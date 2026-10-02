<?php
class Welcome extends BaseController
{
    public const DEFAULT_APP_NAME = 'vayu';

    public function index()
    {
        return $this->respond('/app/page/welcome.php');
    }

    public function hello($app_name = null)
    {
        $app_name = $app_name ?: $this->defaultAppName();

        $data = $this->viewData(
            ['name' => $app_name],
            [
                'api' => $this->apiGet('https://jsonplaceholder.typicode.com/todos/1'),
            ]
        );

        return $this->respond('/app/page/hello.php', $data);
    }
}
?>