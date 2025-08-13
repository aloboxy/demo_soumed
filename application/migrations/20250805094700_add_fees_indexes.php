<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Add_fees_indexes extends CI_Migration {
    public function up() {
        // Add index for fee_allocation table
        $this->db->query('ALTER TABLE `fee_allocation` ADD INDEX `idx_fa_student_session` (`student_id`, `session_id`)');
        $this->db->query('ALTER TABLE `fee_allocation` ADD INDEX `idx_fa_group_session` (`group_id`, `session_id`)');
        
        // Add index for fee_payment_history table
        $this->db->query('ALTER TABLE `fee_payment_history` ADD INDEX `idx_fph_allocation_type` (`allocation_id`, `type_id`)');
        $this->db->query('ALTER TABLE `fee_payment_history` ADD INDEX `idx_fph_date` (`date`)');
        
        // Add index for fee_groups_details table
        $this->db->query('ALTER TABLE `fee_groups_details` ADD INDEX `idx_fgd_group_type` (`fee_groups_id`, `fee_type_id`)');
        
        // Add index for enroll table
        $this->db->query('ALTER TABLE `enroll` ADD INDEX `idx_enroll_class_section` (`class_id`, `section_id`)');
        $this->db->query('ALTER TABLE `enroll` ADD INDEX `idx_enroll_student_session` (`student_id`, `session_id`)');
    }

    public function down() {
        // Remove indexes if needed
        $this->db->query('ALTER TABLE `fee_allocation` DROP INDEX `idx_fa_student_session`');
        $this->db->query('ALTER TABLE `fee_allocation` DROP INDEX `idx_fa_group_session`');
        $this->db->query('ALTER TABLE `fee_payment_history` DROP INDEX `idx_fph_allocation_type`');
        $this->db->query('ALTER TABLE `fee_payment_history` DROP INDEX `idx_fph_date`');
        $this->db->query('ALTER TABLE `fee_groups_details` DROP INDEX `idx_fgd_group_type`');
        $this->db->query('ALTER TABLE `enroll` DROP INDEX `idx_enroll_class_section`');
        $this->db->query('ALTER TABLE `enroll` DROP INDEX `idx_enroll_student_session`');
    }
}
