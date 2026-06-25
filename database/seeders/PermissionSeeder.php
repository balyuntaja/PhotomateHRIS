<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Permission;

class PermissionSeeder extends Seeder
{
  /**
   * Run the database seeds.
   */
  public function run(): void
  {
    // Define permissions for each model
    $permissions = [
      // Cabang permissions
      'view_any_cabang',
      'view_cabang',
      'create_cabang',
      'update_cabang',
      'delete_cabang',
      'delete_any_cabang',
      'force_delete_cabang',
      'force_delete_any_cabang',
      'restore_cabang',
      'restore_any_cabang',
      'replicate_cabang',
      'reorder_cabang',

      // Karyawan permissions
      'view_any_karyawan',
      'view_karyawan',
      'create_karyawan',
      'update_karyawan',
      'delete_karyawan',
      'delete_any_karyawan',
      'force_delete_karyawan',
      'force_delete_any_karyawan',
      'restore_karyawan',
      'restore_any_karyawan',
      'replicate_karyawan',
      'reorder_karyawan',

      // Role permissions
      'view_any_role',
      'view_role',
      'create_role',
      'update_role',
      'delete_role',
      'delete_any_role',
      'force_delete_role',
      'force_delete_any_role',
      'restore_role',
      'restore_any_role',
      'replicate_role',
      'reorder_role',

      // Perusahaan permissions
      'view_any_perusahaan',
      'view_perusahaan',
      'create_perusahaan',
      'update_perusahaan',
      'delete_perusahaan',
      'delete_any_perusahaan',
      'force_delete_perusahaan',
      'force_delete_any_perusahaan',
      'restore_perusahaan',
      'restore_any_perusahaan',
      'replicate_perusahaan',
      'reorder_perusahaan',

      // Absensi permissions
      'view_any_absensi',
      'view_absensi',
      'create_absensi',
      'update_absensi',
      'delete_absensi',
      'delete_any_absensi',
      'force_delete_absensi',
      'force_delete_any_absensi',
      'restore_absensi',
      'restore_any_absensi',
      'replicate_absensi',
      'reorder_absensi',

      // Lembur permissions
      'view_any_lembur',
      'view_lembur',
      'create_lembur',
      'update_lembur',
      'delete_lembur',
      'delete_any_lembur',
      'force_delete_lembur',
      'force_delete_any_lembur',
      'restore_lembur',
      'restore_any_lembur',
      'replicate_lembur',
      'reorder_lembur',

      // Cuti permissions
      'view_any_cuti',
      'view_cuti',
      'create_cuti',
      'update_cuti',
      'delete_cuti',
      'delete_any_cuti',
      'force_delete_cuti',
      'force_delete_any_cuti',
      'restore_cuti',
      'restore_any_cuti',
      'replicate_cuti',
      'reorder_cuti',

      // Izin permissions
      'view_any_izin',
      'view_izin',
      'create_izin',
      'update_izin',
      'delete_izin',
      'delete_any_izin',
      'force_delete_izin',
      'force_delete_any_izin',
      'restore_izin',
      'restore_any_izin',
      'replicate_izin',
      'reorder_izin',
      
      // Penggajian permissions
      'view_any_penggajian',
      'view_penggajian',
      'create_penggajian',
      'update_penggajian',
      'delete_penggajian',
      'delete_any_penggajian',
      'force_delete_penggajian',
      'force_delete_any_penggajian',
      'restore_penggajian',
      'restore_any_penggajian',
      'replicate_penggajian',
      'reorder_penggajian',

      // Slip Gaji permissions
      'view_any_slip_gaji',
      'view_slip_gaji',

      // Laporan Keuangan Permissions
      'menu_laporan_keuangan',
      'view_any_laporan_keuangan',
      'view_laporan_keuangan',
      
      'menu_laporan_kinerja',
      'view_any_laporan_kinerja',
      'view_laporan_kinerja',

      // Article permissions
      'view_any_article', 'view_article', 'create_article', 'update_article', 'delete_article', 'delete_any_article', 'force_delete_article', 'force_delete_any_article', 'restore_article', 'restore_any_article', 'replicate_article', 'reorder_article',

      // BioPhotostrip permissions
      'view_any_bio_photostrip', 'view_bio_photostrip', 'create_bio_photostrip', 'update_bio_photostrip', 'delete_bio_photostrip', 'delete_any_bio_photostrip', 'force_delete_bio_photostrip', 'force_delete_any_bio_photostrip', 'restore_bio_photostrip', 'restore_any_bio_photostrip', 'replicate_bio_photostrip', 'reorder_bio_photostrip',

      // BioSetting permissions
      'view_any_bio_setting', 'view_bio_setting', 'create_bio_setting', 'update_bio_setting', 'delete_bio_setting', 'delete_any_bio_setting', 'force_delete_bio_setting', 'force_delete_any_bio_setting', 'restore_bio_setting', 'restore_any_bio_setting', 'replicate_bio_setting', 'reorder_bio_setting',

      // Client permissions
      'view_any_client', 'view_client', 'create_client', 'update_client', 'delete_client', 'delete_any_client', 'force_delete_client', 'force_delete_any_client', 'restore_client', 'restore_any_client', 'replicate_client', 'reorder_client',

      // Faq permissions
      'view_any_faq', 'view_faq', 'create_faq', 'update_faq', 'delete_faq', 'delete_any_faq', 'force_delete_faq', 'force_delete_any_faq', 'restore_faq', 'restore_any_faq', 'replicate_faq', 'reorder_faq',

      // Gallery permissions
      'view_any_gallery', 'view_gallery', 'create_gallery', 'update_gallery', 'delete_gallery', 'delete_any_gallery', 'force_delete_gallery', 'force_delete_any_gallery', 'restore_gallery', 'restore_any_gallery', 'replicate_gallery', 'reorder_gallery',

      // Schedule permissions
      'view_any_schedule', 'view_schedule', 'create_schedule', 'update_schedule', 'delete_schedule', 'delete_any_schedule', 'force_delete_schedule', 'force_delete_any_schedule', 'restore_schedule', 'restore_any_schedule', 'replicate_schedule', 'reorder_schedule',

      // EmployeeSchedule permissions
      'view_any_employee_schedule', 'view_employee_schedule', 'create_employee_schedule', 'update_employee_schedule', 'delete_employee_schedule', 'delete_any_employee_schedule', 'force_delete_employee_schedule', 'force_delete_any_employee_schedule', 'restore_employee_schedule', 'restore_any_employee_schedule', 'replicate_employee_schedule', 'reorder_employee_schedule',

      // Invoice permissions
      'view_any_invoice', 'view_invoice', 'create_invoice', 'update_invoice', 'delete_invoice', 'delete_any_invoice', 'force_delete_invoice', 'force_delete_any_invoice', 'restore_invoice', 'restore_any_invoice', 'replicate_invoice', 'reorder_invoice',
    ];

    // Create permissions with custom ID format
    foreach ($permissions as $index => $permissionName) {
      $permissionId = 'P' . str_pad($index + 1, 4, '0', STR_PAD_LEFT);

      $existing = Permission::where('name', $permissionName)->where('guard_name', 'web')->first();
      if ($existing) {
          continue;
      }

      Permission::create([
        'permission_id' => $permissionId,
        'name' => $permissionName,
        'guard_name' => 'web',
      ]);
    }

    $this->command->info('Permissions created successfully!');
    $this->command->info('Total permissions created: ' . count($permissions));
  }
}