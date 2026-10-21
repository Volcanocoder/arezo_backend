<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MakeSuperAdmin extends Command
{
    protected $signature = 'app:make-super-admin {mobile : شماره موبایل، مثل 09123456789} {--name= : نام کاربر}';

    protected $description = 'ساخت مدیر ارشد جدید یا ارتقای کاربر موجود به مدیر ارشد';

    public function handle(): int
    {
        $mobile = (string) $this->argument('mobile');

        if (! preg_match('/^09\d{9}$/', $mobile)) {
            $this->error('شماره موبایل باید ۱۱ رقم و با 09 شروع شود.');

            return self::FAILURE;
        }

        $user = User::query()->where('mobile', $mobile)->first();

        if ($user) {
            $user->forceFill(['role' => 'super_admin'])->save();
            $this->info("کاربر «{$user->name}» اکنون مدیر ارشد است.");

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: $this->ask('نام و نام خانوادگی');
        $password = $this->secret('رمز عبور (حداقل ۱۰ نویسه)');

        if (strlen((string) $password) < 10) {
            $this->error('رمز عبور کوتاه است.');

            return self::FAILURE;
        }

        $user = new User();
        $user->name = $name;
        $user->mobile = $mobile;
        $user->password = $password;
        $user->forceFill(['role' => 'super_admin'])->save();

        $this->info('مدیر ارشد ساخته شد.');

        return self::SUCCESS;
    }
}
