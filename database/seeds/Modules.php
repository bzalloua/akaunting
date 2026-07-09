<?php

namespace Database\Seeds;

use App\Abstracts\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class Modules extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();

        $this->create();

        Model::reguard();
    }

    private function create()
    {
        $company_id = $this->command->argument('company');

        foreach (['offline-payments', 'paypal-standard'] as $alias) {
            // Bundled modules ship separately from the core repo; skip
            // auto-install if they're not present on this deployment.
            if (empty(module($alias))) {
                continue;
            }

            Artisan::call('module:install', [
                'alias'     => $alias,
                'company'   => $company_id,
                'locale'    => session('locale', company($company_id)->locale),
            ]);
        }
    }
}
