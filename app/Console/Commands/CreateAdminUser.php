<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

#[Signature('app:create-admin-user')]
#[Description('Create a new administrator account')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        $name = $this->ask('نام مدیر را وارد کنید');

        if (! is_string($name) || trim($name) === '') {
            $this->components->error('نام نمی‌تواند خالی باشد.');
            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->ask('ایمیل مدیر را وارد کنید')));

        $validator = Validator::make(
            ['email' => $email],
            [
                'email' => [
                    'required',
                    'email',
                    'max:255',
                    'regex:/^[a-zA-Z0-9._%+-]+@gmail\.com$/i',
                    'unique:users,email',
                ],
            ],
            [
                'email.regex' => 'لطفاً با حساب گوگل وارد شوید.',
            ]
        );

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('email'));
            return self::FAILURE;
        }

        $password = $this->secret('رمز عبور را وارد کنید');
        $passwordConfirmation = $this->secret('رمز عبور را مجدداً وارد کنید');

        if (! is_string($password) || strlen($password) < 8) {
            $this->components->error('رمز عبور باید حداقل 8 کاراکتر باشد.');
            return self::FAILURE;
        }

        if ($password !== $passwordConfirmation) {
            $this->components->error('رمزهای عبور یکسان نیستند.');
            return self::FAILURE;
        }

        $user = new User();
        $user->name = trim($name);
        $user->email = strtolower(trim($email));
        $user->password = Hash::make($password);
        $user->is_admin = true;
        $user->save();

        $this->components->info('حساب مدیر با موفقیت ایجاد شد.');

        return self::SUCCESS;
    }
}
