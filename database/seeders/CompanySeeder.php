<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'password';

    private const COMPANIES = [
        ['name' => 'Фатир Агро', 'email' => 'info@ordifatir.com'],
        ['name' => 'Химус', 'email' => 'info@chymetj.com'],
        ['name' => 'Ширинихои Анас', 'email' => 'anas_sw@gmail.com'],
        ['name' => 'Kompazit Group', 'email' => 'atex-en@mail.ru'],
    ];

    public function run(): void
    {
        foreach (self::COMPANIES as $data) {
            $this->seedCompany($data['name'], $data['email']);
        }
    }

    private function seedCompany(string $name, string $email): void
    {
        $company = Company::query()->updateOrCreate(
            ['name' => $name],
            ['is_active' => true]
        );

        $admin = User::query()->firstOrNew(['email' => $email]);

        if (!$admin->exists) {
            $admin->password = self::DEFAULT_PASSWORD;
            $admin->email_verified_at = now();
        }

        $admin->fill([
            'name' => 'Админ',
            'company_id' => $company->id,
            'is_active' => true,
        ])->save();

        $company->update(['owner_id' => $admin->id]);

        $admin->syncRoles('company_admin');
    }
}
