<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::updateOrCreate([
            'email' => 'test@example.com',
        ], [
            'name' => 'Test User',
            'password' => Hash::make('licitaciones.2026'),
        ]);

        $company = $user->companies()->updateOrCreate([
            'rfc' => 'ABC010203AB1',
        ], [
            'nombre' => 'Comercializadora Licitaciones SA de CV',
        ]);

        $company->actas()->updateOrCreate([
            'tipo' => 'constitutiva',
            'rpc_folio' => 'RPC-001-2026',
        ], [
            'fecha_registro' => now()->subYears(5)->toDateString(),
            'rpc_lugar' => 'Ciudad de Mexico',
            'notaria_numero' => '45',
            'notaria_lugar' => 'CDMX',
            'notario_nombre' => 'Lic. Juan Perez',
            'escritura_numero' => '12345',
            'libro_numero' => '12',
            'acto' => 'Constitucion de sociedad',
        ]);

        $company->opinionesCumplimiento()->updateOrCreate([
            'tipo' => 'sat',
            'fecha_emision' => now()->subDays(5)->toDateString(),
        ], [
            'estado' => 'positivo',
            'fecha_vigencia' => null,
        ]);

        $user->regulations()->updateOrCreate([
            'title' => 'LAASSP',
            'country_code' => 'MX',
            'scope' => 'federal',
        ], [
            'regulatory_body' => 'Secretaria de Hacienda',
            'general_description' => 'Ley de Adquisiciones, Arrendamientos y Servicios del Sector Publico.',
            'is_active' => true,
        ]);
    }
}
