<?php

namespace App\Console\Commands;

use App\Models\Bookkeeping\Category;
use App\Models\User;
use App\Services\PlatformAccessService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('platform:initialize {--email= : 店主联系邮箱} {--username= : 店主登录用户名} {--name=店主 : 店主名称} {--generate-password : 为新账号生成随机初始密码}')]
#[Description('初始化平台、服务授权和默认收支分类；重复执行不覆盖账号密码或业务数据')]
class InitializePlatform extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->option('email');
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('请通过 --email 提供有效联系邮箱。');

            return self::FAILURE;
        }
        $existing = User::where('email', $email)->first();
        $password = null;
        $username = null;
        if (! $existing) {
            $username = $this->option('username');
            if (! is_string($username)) {
                $this->error('新账号需要通过 --username 提供登录用户名。');

                return self::FAILURE;
            }
            Validator::make(['username' => $username], ['username' => ['required', 'string', 'max:255', 'unique:users,username']])->validate();
            if ($this->option('generate-password')) {
                $password = Str::password(24);
            } elseif ($this->input->isInteractive()) {
                $password = $this->secret('设置登录密码（至少7位）');
            } else {
                $this->error('新账号需要交互设置密码，或使用 --generate-password。');

                return self::FAILURE;
            }
            Validator::make(['password' => $password], ['password' => ['required', 'string', 'min:7']])->validate();
        }
        $user = DB::transaction(function () use ($email, $existing, $password, $username): User {
            $user = $existing ?? User::create(['username' => $username, 'name' => $this->option('name'), 'email' => $email, 'password' => $password]);
            app(PlatformAccessService::class)->provision($user);
            foreach ([['其他收入', 'income'], ['营业收入', 'income'], ['其他支出', 'expense'], ['餐饮食材', 'expense'], ['水电房租', 'expense']] as [$name, $direction]) {
                Category::firstOrCreate(['name' => $name, 'direction' => $direction]);
            }

            return $user;
        });
        $this->info('平台初始化完成；已创建默认收支分类，未创建收支记录。');
        $this->line('登录用户名：'.$user->username);
        if ($password && $this->option('generate-password')) {
            $this->line('初始密码：'.$password);
        }

        return self::SUCCESS;
    }
}
