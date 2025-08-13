<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Fees_model extends MY_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('sms_model');
    }

    /**
     * Calculate fine for late fee payment
     * 
     * @param int $allocationID The fee allocation ID
     * @param int $typeID The fee type ID
     * @return float The calculated fine amount
     */
    public function feeFineCalculation($allocationID, $typeID)
    {
        $sessionID = get_session_id();
        
        // Use query bindings for security
        $this->db->select('fd.amount, fd.due_date, f.*')
                 ->from('fee_allocation as a')
                 ->join('fee_groups_details as fd', 'fd.fee_groups_id = a.group_id AND fd.fee_type_id = ?', 'left')
                 ->join('fee_fine as f', 'f.group_id = fd.fee_groups_id AND f.type_id = fd.fee_type_id AND f.session_id = ?', 'inner')
                 ->where('a.id', $allocationID);
        
        $getDB = $this->db->get('', [$typeID, $sessionID])->row_array();
        if (is_array($getDB) && count($getDB)) {
            $dueDate = $getDB['due_date'];
            if (strtotime($dueDate) < strtotime(date('Y-m-d'))) {
                $feeAmount = $getDB['amount'];
                $feeFrequency = $getDB['fee_frequency'];
                $fineValue = $getDB['fine_value'];
                if ($getDB['fine_type'] == 1) {
                    $fineAmount = $fineValue;
                } else {
                    $fineAmount = ($feeAmount / 100) * $fineValue;
                }
                $now = time(); // or your date as well
                $dueDate = strtotime($dueDate);
                $datediff = $now - $dueDate;
                $overDay = round($datediff / (60 * 60 * 24));
                if ($feeFrequency != 0) {
                    $fineAmount = ($overDay / $feeFrequency) * $fineAmount;
                }
                return $fineAmount;
            } else {
                return 0;
            }
        } else {
            return 0;
        }
    }

    /**
     * Get student allocation list with optimized query
     * 
     * @param int $classID
     * @param string $sectionID
     * @param int $groupID
     * @param int $branchID
     * @return array
     */
    public function getStudentAllocationList($classID, $sectionID, $groupID, $branchID)
    {
        $sessionID = get_session_id();
        
        // Use query builder for better security and readability
        $this->db->select([
            'e.student_id', 'e.roll', 'e.class_id', 'e.section_id',
            's.photo', 's.first_name', 's.last_name', 's.gender', 
            's.register_no', 's.parent_id', 's.email', 's.mobileno',
            'IFNULL(fa.id, 0) as allocation_id', 'fa.group_id'
        ]);
        
        $this->db->from('enroll as e');
        $this->db->join('student as s', 's.id = e.student_id', 'left');
        $this->db->join('login_credential as l', "l.user_id = s.id AND l.role = '7'", 'left', false);
        $this->db->join("fee_allocation as fa", "fa.student_id = e.student_id 
            AND fa.group_id = ? 
            AND fa.session_id = ?", 'left', false);
            
        $this->db->where('e.class_id', $classID);
        $this->db->where('e.branch_id', $branchID);
        $this->db->where('e.session_id', $sessionID);
        
        if ($sectionID != 'all') {
            $this->db->where('e.section_id', $sectionID);
        }
        
        $this->db->order_by('s.id', 'ASC');
        
        // Use query bindings for security
        $query = $this->db->query(
            $this->db->get_compiled_select(), 
            [$groupID, $sessionID]
        );
        
        $results = $query->result_array();
        
        // Process results to ensure consistent format
        foreach ($results as &$row) {
            $row['fullname'] = trim($row['first_name'] . ' ' . $row['last_name']);
            unset($row['first_name'], $row['last_name']);
        }
        
        return $results;
    }

    /**
     * Get invoice status and invoice number for a student
     * Optimized to use a single query instead of multiple queries
     * 
     * @param int $studentID
     * @return array
     */
    public function getInvoiceStatus($studentID)
    {
        $sessionID = get_session_id();
        
        // Single query with parameter binding for security
        $sql = "SELECT 
            (SELECT COUNT(*) FROM fee_allocation 
             WHERE student_id = ? AND session_id = ?) as has_allocation,
            (SELECT IFNULL(SUM(gd.amount), 0)
             FROM fee_allocation fa
             INNER JOIN fee_groups_details gd ON gd.fee_groups_id = fa.group_id 
             WHERE fa.student_id = ? AND fa.session_id = ?) as total_fees,
            (SELECT IFNULL(SUM(h.amount + h.discount), 0) 
             FROM fee_payment_history h
             INNER JOIN fee_allocation a ON h.allocation_id = a.id
             WHERE a.student_id = ? AND a.session_id = ?) as total_paid,
            (SELECT MIN(id) FROM fee_allocation 
             WHERE student_id = ? AND session_id = ?) as min_allocation_id";
        
        $params = [
            $studentID, $sessionID,  // has_allocation
            $studentID, $sessionID,  // total_fees
            $studentID, $sessionID,  // total_paid
            $studentID, $sessionID   // min_allocation_id
        ];
        
        $result = $this->db->query($sql, $params)->row_array();
        
        // Determine status
        $status = 'unpaid';
        if ($result['has_allocation'] == 0) {
            $status = 'no_allocation';
        } elseif ($result['total_fees'] > 0 && $result['total_paid'] >= $result['total_fees']) {
            $status = 'total';
        } elseif ($result['total_paid'] > 0) {
            $status = 'partly';
        }
        
        return [
            'status' => $status,
            'invoice_no' => str_pad($result['min_allocation_id'], 4, '0', STR_PAD_LEFT)
        ];
    }

    /**
     * Get invoice details for a student
     * 
     * @param int $studentID The student ID
     * @return array Invoice details
     */
    public function getInvoiceDetails($studentID)
    {
        $sessionID = get_session_id();
        
        $this->db->select([
            'fa.id as allocation_id', 
            'ft.name', 
            'fgd.amount', 
            'fgd.due_date', 
            'fgd.fee_type_id'
        ]);
        $this->db->from('fee_allocation fa')
                 ->join('fee_groups_details fgd', 'fgd.fee_groups_id = fa.group_id', 'left')
                 ->join('fees_type ft', 'ft.id = fgd.fee_type_id', 'left')
                 ->where('fa.student_id', $studentID)
                 ->where('fa.session_id', $sessionID);
        
        return $this->db->get()->result_array();
    }

    /**
     * Get basic student and school information for invoice
     * 
     * @param int $studentID The student ID
     * @return array Student and school information
     */
    public function getInvoiceBasic($studentID)
    {
        $this->db->select([
            's.id',
            'e.branch_id',
            's.first_name',
            's.last_name',
            's.email as student_email',
            's.current_address as student_address',
            'c.name as class_name',
            'b.school_name',
            'b.email as school_email',
            'b.mobileno as school_mobileno',
            'b.address as school_address'
        ]);
        $this->db->from('enroll e')
                 ->join('student s', 's.id = e.student_id', 'inner')
                 ->join('class c', 'c.id = e.class_id', 'left')
                 ->join('branch b', 'b.id = e.branch_id', 'left')
                 ->where('e.student_id', $studentID)
                 ->limit(1);
                 
        return $this->db->get()->row_array();
    }
    
    /**
     * Get total deposit, discount, and fine for a fee allocation and type
     * 
     * @param int $allocationID The fee allocation ID
     * @param int $typeID The fee type ID
     * @return array Aggregated payment information
     */
    public function getStudentFeeDeposit($allocationID, $typeID)
    {
        $this->db->select([
            "IFNULL(SUM(amount), '0.00') as total_amount",
            "IFNULL(SUM(discount), '0.00') as total_discount",
            "IFNULL(SUM(fine), '0.00') as total_fine"
        ]);
        $this->db->from('fee_payment_history')
                 ->where('allocation_id', $allocationID)
                 ->where('type_id', $typeID);
        
        return $this->db->get()->row_array();
    }

    /**
     * Get payment history for a fee allocation
     * 
     * @param int $allocationID The fee allocation ID
     * @param int $groupID The fee group ID (unused in current implementation)
     * @return array Payment history records
     */
    public function getPaymentHistory($allocationID, $groupID = null)
    {
        $this->db->select([
            'h.*',
            't.name',
            't.fee_code',
            'pt.name as payvia'
        ]);
        $this->db->from('fee_payment_history h')
                 ->join('fees_type t', 't.id = h.type_id', 'left')
                 ->join('payment_types pt', 'pt.id = h.pay_via', 'left')
                 ->where('h.allocation_id', $allocationID)
                 ->order_by('h.id', 'asc');
        
        return $this->db->get()->result_array();
    }

    public function typeSave($data)
    {
        $arrayData = array(
            'branch_id' => $this->application_model->get_branch_id(),
            'name' => $data['type_name'],
            'fee_code' => strtolower(str_replace(' ', '-', $data['type_name'])),
            'description' => $data['description'],
        );
        if (!isset($data['type_id'])) {
            $this->db->insert('fees_type', $arrayData);
        } else {
            $this->db->where('id', $data['type_id']);
            $this->db->update('fees_type', $arrayData);
        }
    }


    // add partly of the fee
    public function add_fees($data, $id = '')
    {
        $total_due = get_type_name_by_id('fee_invoice', $id, 'total_due');
        $payment_amount = $data['amount'];
        if (($payment_amount <= $total_due) && ($payment_amount > 0)) {
            $arrayHistory = array(
                'fee_invoice_id' => $id,
                'collect_by' => get_user_stamp(),
                'remarks' => $data['remarks'],
                'method' => $data['method'],
                'amount' => $payment_amount,
                'date' => date("Y-m-d"),
                'session_id' => get_session_id(),
            );
            $this->db->insert('payment_history', $arrayHistory);

            if ($total_due <= $payment_amount) {
                $this->db->where('id', $id);
                $this->db->update('fee_invoice', array('status' => 2));
            } else {
                $this->db->where('id', $id);
                $this->db->update('fee_invoice', array('status' => 1));
            }
            $this->db->where('id', $id);
            $this->db->set('total_paid', 'total_paid + ' . $payment_amount, false);
            $this->db->set('total_due', 'total_due - ' . $payment_amount, false);
            $this->db->update('fee_invoice');

            // send payment confirmation sms
            $arrayHistory['student_id'] = $data['student_id'];
            $arrayHistory['timestamp'] = date("Y-m-d");
            $this->sms_model->send_sms($arrayHistory, 2);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get invoice list with optimized query
     * 
     * @param int $class_id
     * @param string $section_id
     * @param int $branch_id
     * @return array
     */
    public function getInvoiceList($class_id, $section_id, $branch_id)
    {
        $session_id = get_session_id();
        
        // First, get all student IDs that match the criteria
        $this->db->distinct();
        $this->db->select('e.student_id');
        $this->db->from('enroll as e');
        $this->db->join('fee_allocation as fa', 'fa.student_id = e.student_id', 'inner');
        $this->db->where('fa.branch_id', $branch_id);
        $this->db->where('fa.session_id', $session_id);
        $this->db->where('e.class_id', $class_id);
        $this->db->where('e.session_id', $session_id);
        
        if ($section_id != 'all') {
            $this->db->where('e.section_id', $section_id);
        }
        
        $student_ids = array_column($this->db->get()->result_array(), 'student_id');
        
        if (empty($student_ids)) {
            return [];
        }
        
        // Get all fee groups for these students in one query
        $this->db->select('fa.student_id, g.name as group_name');
        $this->db->from('fee_allocation as fa');
        $this->db->join('fee_groups as g', 'g.id = fa.group_id', 'inner');
        $this->db->where_in('fa.student_id', $student_ids);
        $this->db->where('fa.session_id', $session_id);
        $groups_result = $this->db->get()->result_array();
        
        // Organize fee groups by student ID
        $student_groups = [];
        foreach ($groups_result as $group) {
            if (!isset($student_groups[$group['student_id']])) {
                $student_groups[$group['student_id']] = [];
            }
            $student_groups[$group['student_id']][] = $group;
        }
        
        // Get all student details in one query
        $this->db->select([
            'e.student_id', 'e.roll', 
            's.first_name', 's.last_name', 's.register_no', 's.mobileno',
            'c.name as class_name', 'se.name as section_name'
        ]);
        $this->db->from('enroll as e');
        $this->db->join('student as s', 's.id = e.student_id', 'left');
        $this->db->join('class as c', 'c.id = e.class_id', 'left');
        $this->db->join('section as se', 'se.id = e.section_id', 'left');
        $this->db->where_in('e.student_id', $student_ids);
        $this->db->where('e.class_id', $class_id);
        $this->db->where('e.session_id', $session_id);
        
        if ($section_id != 'all') {
            $this->db->where('e.section_id', $section_id);
        }
        
        $this->db->order_by('e.roll', 'asc');
        $students = $this->db->get()->result_array();
        
        // Combine the data
        foreach ($students as &$student) {
            $student_id = $student['student_id'];
            $student['feegroup'] = isset($student_groups[$student_id]) ? $student_groups[$student_id] : [];
        }
        
        return $students;
    }

    /**
     * Get due invoice list with optimized query
     * 
     * @param int $class_id
     * @param string $section_id
     * @param int $feegroup_id
     * @param int $fee_feetype_id
     * @return array
     */
    public function getDueInvoiceList($class_id, $section_id, $feegroup_id, $fee_feetype_id)
    {
        $session_id = get_session_id();
        
        // First, get all student IDs that match the criteria
        $this->db->distinct();
        $this->db->select('e.student_id, e.id as enroll_id');
        $this->db->from('enroll as e');
        $this->db->join('fee_allocation as fa', 'fa.student_id = e.student_id', 'inner');
        $this->db->join('fee_groups_details as gd', 'gd.fee_groups_id = fa.group_id AND gd.fee_type_id = ' . $this->db->escape($fee_feetype_id), 'inner');
        $this->db->where('fa.group_id', $feegroup_id);
        $this->db->where('e.class_id', $class_id);
        $this->db->where('e.session_id', $session_id);
        
        if ($section_id != 'all') {
            $this->db->where('e.section_id', $section_id);
        }
        
        $student_ids = array_column($this->db->get()->result_array(), 'student_id');
        
        if (empty($student_ids)) {
            return [];
        }
        
        // Get all fee groups for these students in one query
        $this->db->select('fa.student_id, g.name as group_name');
        $this->db->from('fee_allocation as fa');
        $this->db->join('fee_groups as g', 'g.id = fa.group_id', 'inner');
        $this->db->where_in('fa.student_id', $student_ids);
        $this->db->where('fa.session_id', $session_id);
        $groups_result = $this->db->get()->result_array();
        
        // Organize fee groups by student ID
        $student_groups = [];
        foreach ($groups_result as $group) {
            if (!isset($student_groups[$group['student_id']])) {
                $student_groups[$group['student_id']] = [];
            }
            $student_groups[$group['student_id']][] = $group;
        }
        
        // Get all payment history for these students in one query
        $this->db->select([
            'fa.student_id',
            'h.amount as paid_amount',
            'h.discount as total_discount',
            'h.type_id',
            'h.allocation_id'
        ]);
        $this->db->from('fee_payment_history as h');
        $this->db->join('fee_allocation as fa', 'fa.id = h.allocation_id', 'inner');
        $this->db->where_in('fa.student_id', $student_ids);
        $this->db->where('h.type_id', $fee_feetype_id);
        $payments_result = $this->db->get()->result_array();
        
        // Organize payments by student ID and type
        $student_payments = [];
        foreach ($payments_result as $payment) {
            $student_id = $payment['student_id'];
            if (!isset($student_payments[$student_id])) {
                $student_payments[$student_id] = [
                    'total_amount' => '0',
                    'total_discount' => '0'
                ];
            }
            $student_payments[$student_id]['total_amount'] += $payment['paid_amount'];
            $student_payments[$student_id]['total_discount'] += $payment['total_discount'];
        }
        
        // Get fee group details
        $this->db->select('amount as full_amount, due_date');
        $this->db->from('fee_groups_details');
        $this->db->where('fee_groups_id', $feegroup_id);
        $this->db->where('fee_type_id', $fee_feetype_id);
        $fee_details = $this->db->get()->row_array();
        
        if (empty($fee_details)) {
            return [];
        }
        
        // Get all student details in one query
        $this->db->select([
            'e.student_id', 'e.roll', 'e.id as enroll_id',
            's.first_name', 's.last_name', 's.register_no', 's.mobileno',
            'c.name as class_name', 'se.name as section_name'
        ]);
        $this->db->from('enroll as e');
        $this->db->join('student as s', 's.id = e.student_id', 'left');
        $this->db->join('class as c', 'c.id = e.class_id', 'left');
        $this->db->join('section as se', 'se.id = e.section_id', 'left');
        $this->db->where_in('e.student_id', $student_ids);
        $this->db->where('e.class_id', $class_id);
        $this->db->where('e.session_id', $session_id);
        
        if ($section_id != 'all') {
            $this->db->where('e.section_id', $section_id);
        }
        
        $this->db->order_by('e.roll', 'asc');
        $students = $this->db->get()->result_array();
        
        // Combine all the data
        $result = [];
        foreach ($students as $student) {
            $student_id = $student['student_id'];
            
            $result[] = [
                'student_id' => $student_id,
                'enroll_id' => $student['enroll_id'],
                'roll' => $student['roll'],
                'first_name' => $student['first_name'],
                'last_name' => $student['last_name'],
                'register_no' => $student['register_no'],
                'mobileno' => $student['mobileno'],
                'class_name' => $student['class_name'],
                'section_name' => $student['section_name'],
                'full_amount' => $fee_details['full_amount'],
                'due_date' => $fee_details['due_date'],
                'total_amount' => isset($student_payments[$student_id]) ? $student_payments[$student_id]['total_amount'] : '0',
                'total_discount' => isset($student_payments[$student_id]) ? $student_payments[$student_id]['total_discount'] : '0',
                'feegroup' => isset($student_groups[$student_id]) ? $student_groups[$student_id] : []
            ];
        }
        
        return $result;
    }

    public function getDueReport($class_id='', $section_id='')
    {
        $this->db->select('fa.id as allocation_id,sum(gd.amount) as total_fees,e.student_id,e.roll,s.first_name,s.last_name,s.register_no,s.mobileno,c.name as class_name,se.name as section_name');
        $this->db->from('fee_allocation as fa');
        $this->db->join('fee_groups_details as gd', 'gd.fee_groups_id = fa.group_id', 'left');
        $this->db->join('enroll as e', 'e.student_id = fa.student_id', 'inner');
        $this->db->join('student as s', 's.id = e.student_id', 'left');
        $this->db->join('class as c', 'c.id = e.class_id', 'left');
        $this->db->join('section as se', 'se.id = e.section_id', 'left');
        $this->db->where('fa.session_id', get_session_id());
        $this->db->where('e.class_id', $class_id);
        if (!empty($section_id)){
            $this->db->where('e.section_id', $section_id);
        }
        $this->db->group_by('fa.student_id');
        $this->db->order_by('e.roll', 'asc');
        $result = $this->db->get()->result_array();
        foreach ($result as $key => $value) {
            $result[$key]['payment'] = $this->getPaymentDetails($value['student_id']);
        }
        return $result;
    }

    function getPaymentDetails($student_id)
    {
        $this->db->select('IFNULL(SUM(amount), 0) as total_paid, IFNULL(SUM(discount), 0) as total_discount, IFNULL(SUM(fine), 0) as total_fine');
        $this->db->from('fee_allocation');
        $this->db->join('fee_payment_history', 'fee_payment_history.allocation_id = fee_allocation.id', 'left');
        $this->db->where('fee_allocation.student_id', $student_id);
        return  $this->db->get()->row_array();
    }

    /**
     * Get payment history with filtering options
     * 
     * @param string $classID Filter by class ID (optional)
     * @param string $SectionID Filter by section ID (optional)
     * @param string $paymentVia Filter by payment method (optional)
     * @param string $start Start date for filtering
     * @param string $end End date for filtering
     * @param int $branchID Branch ID filter
     * @param bool $onlyFine Only show records with fine > 0
     * @return array Payment history records
     */
    public function getStuPaymentHistory($classID = '', $SectionID = '', $paymentVia, $start, $end, $branchID, $onlyFine = false)
    {
        $sessionID = get_session_id();
        
        $this->db->select([
            'h.*',
            't.name',
            't.fee_code',
            'pt.name as payvia',
            's.first_name',
            's.last_name',
            's.register_no',
            'c.name as class_name',
            'se.name as section_name'
        ]);
        
        $this->db->from('fee_payment_history h')
                 ->join('fees_type t', 't.id = h.type_id', 'left')
                 ->join('payment_types pt', 'pt.id = h.pay_via', 'left')
                 ->join('enroll e', 'e.student_id = h.student_id', 'inner')
                 ->join('student s', 's.id = e.student_id', 'inner')
                 ->join('class c', 'c.id = e.class_id', 'left')
                 ->join('section se', 'se.id = e.section_id', 'left')
                 ->where('h.payment_date >=', $start)
                 ->where('h.payment_date <=', $end)
                 ->where('e.branch_id', $branchID)
                 ->where('e.session_id', $sessionID);
        
        // Apply filters conditionally
        if (!empty($classID)) {
            $this->db->where('e.class_id', $classID);
        }
        if (!empty($SectionID)) {
            $this->db->where('e.section_id', $SectionID);
        }
        if ($paymentVia != 'all') {
            $this->db->where('h.pay_via', $paymentVia);
        }
        if ($onlyFine) {
            $this->db->where('h.fine >', 0);
        }
        
        $this->db->order_by('h.id', 'asc');
        return $this->db->get()->result_array();
    }

    /**
     * Get payment report with filtering options
     * 
     * @param string $classID Filter by class ID (optional)
     * @param string $sectionID Filter by section ID (optional)
     * @param string $studentID Filter by student ID (optional)
     * @param string $typeID Filter by fee type ID (optional)
     * @param string $start Start date for filtering
     * @param string $end End date for filtering
     * @param int $branchID Branch ID filter
     * @return array Payment report records
     */
    public function getStuPaymentReport($classID = '', $sectionID = '', $studentID = '', $typeID = '', $start, $end, $branchID)
    {
        $sessionID = get_session_id();
        
        $this->db->select([
            'h.*',
            't.name',
            't.fee_code',
            'pt.name as payvia',
            's.first_name',
            's.last_name',
            's.register_no',
            'c.name as class_name',
            'se.name as section_name'
        ]);
        
        $this->db->from('fee_payment_history h')
                 ->join('fees_type t', 't.id = h.type_id', 'left')
                 ->join('payment_types pt', 'pt.id = h.pay_via', 'left')
                 ->join('enroll e', 'e.student_id = h.student_id', 'inner')
                 ->join('student s', 's.id = e.student_id', 'inner')
                 ->join('class c', 'c.id = e.class_id', 'left')
                 ->join('section se', 'se.id = e.section_id', 'left')
                 ->where('h.payment_date >=', $start)
                 ->where('h.payment_date <=', $end)
                 ->where('e.branch_id', $branchID)
                 ->where('e.session_id', $sessionID);
        
        // Apply filters conditionally
        if (!empty($classID)) {
            $this->db->where('e.class_id', $classID);
        }
        if (!empty($sectionID)) {
            $this->db->where('e.section_id', $sectionID);
        }
        if (!empty($studentID)) {
            $this->db->where('h.student_id', $studentID);
        }
        if (!empty($typeID)) {
            $this->db->where('h.type_id', $typeID);
        }
        
        $this->db->order_by('h.id', 'asc');
        return $this->db->get()->result_array();
    }

    function getfeeGroup($studentID)
    {
        $this->db->select('g.name');
        $this->db->from('fee_allocation as fa');
        $this->db->join('fee_groups as g', 'g.id = fa.group_id', 'inner');
        $this->db->where('fa.student_id', $studentID);
        $this->db->where('fa.session_id', get_session_id());
        return $this->db->get()->result_array();
    }

    function reminderSave($data)
    {
        $arrayData = array(
            'frequency' => $data['frequency'], 
            'days' => $data['days'], 
            'student' => (isset($data['chk_student']) ? 1 : 0), 
            'guardian' => (isset($data['chk_guardian']) ? 1 : 0), 
            'message' => $data['message'], 
            'branch_id' => $data['branch_id'], 
        );
        if (!isset($data['reminder_id'])) {
            $this->db->insert('fees_reminder', $arrayData);
        } else {
            $this->db->where('id', $data['reminder_id']);
            $this->db->update('fees_reminder', $arrayData);
        }  
    }

    /**
     * Get fee reminders by due date and branch
     * 
     * @param string $date The due date to check (YYYY-MM-DD format)
     * @param int $branch_id The branch ID to filter by
     * @return array List of fee groups with due dates matching the specified date
     */
    public function getFeeReminderByDate($date, $branch_id)
    {
        $this->db->select([
            'fgd.*',
            'ft.name as fee_type_name',
            'fg.name as group_name'
        ]);
        
        $this->db->from('fee_groups_details fgd')
                 ->join('fees_type ft', 'ft.id = fgd.fee_type_id', 'inner')
                 ->join('fee_groups fg', 'fg.id = fgd.fee_groups_id', 'left')
                 ->where('fgd.due_date', $date)
                 ->where('ft.branch_id', $branch_id)
                 ->order_by('fgd.id', 'asc');
        
        return $this->db->get()->result_array();
    }

    /**
     * Get students list for reminder with optimized query
     * 
     * @param int $groupID
     * @param int $typeID
     * @return array
     */
    function getStudentsListReminder($groupID = '', $typeID = '')
    {
        if (empty($groupID) || empty($typeID)) {
            return [];
        }
        
        $sessionID = get_session_id();
        
        // Get all allocations with student and parent data in one query
        $this->db->select([
            'a.id as allocation_id',
            'a.student_id',
            'CONCAT(s.first_name, " ", s.last_name) as child_name',
            's.mobileno as child_mobileno',
            's.parent_id',
            'pr.name as guardian_name',
            'pr.mobileno as guardian_mobileno'
        ]);
        $this->db->from('fee_allocation as a');
        $this->db->join('student as s', 's.id = a.student_id', 'inner');
        $this->db->join('parent as pr', 'pr.id = s.parent_id', 'left');
        $this->db->where('a.group_id', $groupID);
        $this->db->where('a.session_id', $sessionID);
        $allocations = $this->db->get()->result_array();
        
        if (empty($allocations)) {
            return [];
        }
        
        // Get all payment details in one query
        $allocation_ids = array_column($allocations, 'allocation_id');
        $this->db->select([
            'allocation_id',
            'type_id',
            'IFNULL(SUM(amount), 0) as total_paid',
            'IFNULL(SUM(discount), 0) as total_discount'
        ]);
        $this->db->from('fee_payment_history');
        $this->db->where_in('allocation_id', $allocation_ids);
        $this->db->where('type_id', $typeID);
        $this->db->group_by(['allocation_id', 'type_id']);
        $payments_result = $this->db->get()->result_array();
        
        // Organize payments by allocation_id and type_id
        $payments = [];
        foreach ($payments_result as $payment) {
            $key = $payment['allocation_id'] . '_' . $payment['type_id'];
            $payments[$key] = [
                'total_paid' => $payment['total_paid'],
                'total_discount' => $payment['total_discount']
            ];
        }
        
        // Combine the data
        $result = [];
        foreach ($allocations as $allocation) {
            $payment_key = $allocation['allocation_id'] . '_' . $typeID;
            $allocation['payment'] = isset($payments[$payment_key]) ? $payments[$payment_key] : [
                'total_paid' => '0',
                'total_discount' => '0'
            ];
            $result[] = $allocation;
        }
        
        return $result;
    }

    function getPaymentDetailsByTypeID($allocationID, $typeID)
    {
        $this->db->select('IFNULL(SUM(amount), 0) as total_paid, IFNULL(SUM(discount), 0) as total_discount');
        $this->db->from('fee_payment_history');
        $this->db->where('allocation_id', $allocationID);
        $this->db->where('type_id', $typeID);
        return $this->db->get()->row_array();
    }

    public function depositAmountVerify($amount)
    {
        if ($amount != "") {
            $typeID = $this->input->post('fees_type');
            $feesType = explode("|", $typeID);
            $remainAmount = $this->getBalance($feesType[0], $feesType[1]);
            $discount = (isset($_POST['discount_amount']) ? $_POST['discount_amount'] : 0);
            $depositAmount = $amount + $discount;
            if ($remainAmount['balance'] < $depositAmount) {
                $this->form_validation->set_message('deposit_verify', 'Amount cannot be greater than the remaining.');
                return false;
            } else {
                return true;
            }
        }
        return true;
    }

    /**
     * Get the balance and fine for a fee allocation and type
     * Optimized to use a single query instead of multiple queries
     * 
     * @param int $allocationID
     * @param int $typeID
     * @return array
     */
    public function getBalance($allocationID, $typeID)
    {
        $sql = "SELECT 
            (SELECT gd.amount 
             FROM fee_groups_details gd 
             JOIN fee_allocation fa ON gd.fee_groups_id = fa.group_id 
             WHERE fa.id = ? AND gd.fee_type_id = ?) as total_amount,
            (SELECT IFNULL(SUM(amount + discount), 0) 
             FROM fee_payment_history 
             WHERE allocation_id = ? AND type_id = ?) as total_paid,
            (SELECT IFNULL(SUM(fine), 0) 
             FROM fee_payment_history 
             WHERE allocation_id = ? AND type_id = ?) as total_fine";
        
        $params = [$allocationID, $typeID, $allocationID, $typeID, $allocationID, $typeID];
        $result = $this->db->query($sql, $params)->row_array();
        
        $balance = $result['total_amount'] - $result['total_paid'];
        return [
            'balance' => max(0, $balance), // Ensure balance is not negative
            'fine' => $result['total_fine']
        ];
    }

    /**
     * Save a financial transaction for fee payment
     * 
     * @param array $data Transaction data including account_id, date, amount, etc.
     * @return int|bool The transaction ID on success, false on failure
     */
    public function saveTransaction($data)
    {
        // Start database transaction for data consistency
        $this->db->trans_start();
        
        try {
            $branchID = $this->application_model->get_branch_id();
            $accountID = (int)$data['account_id'];
            $date = $data['date'];
            $amount = (float)$data['amount'];
            
            // Get current account balance with proper escaping
            $account = $this->db->select('balance')
                              ->from('accounts')
                              ->where('id', $accountID)
                              ->get()
                              ->row_array();
            
            if (!$account) {
                throw new Exception('Invalid account selected');
            }
            
            $currentBalance = (float)$account['balance'];
            $newBalance = $currentBalance + $amount;
            
            // Find or create the voucher head
            $voucherHead = [
                'name'      => 'Student Fees Collection',
                'type'      => 'income',
                'system'    => 1,
                'branch_id' => $branchID
            ];
            
            $query = $this->db->select('id')
                            ->from('voucher_head')
                            ->where($voucherHead)
                            ->limit(1)
                            ->get();
            
            if ($query->num_rows() > 0) {
                $voucher_headID = $query->row()->id;
            } else {
                $this->db->insert('voucher_head', $voucherHead);
                $voucher_headID = $this->db->insert_id();
                
                if (!$voucher_headID) {
                    throw new Exception('Failed to create voucher head');
                }
            }
            
            // Insert transaction record
            $transactionData = [
                'account_id'      => $accountID,
                'voucher_head_id' => $voucher_headID,
                'type'            => 'income',
                'amount'          => $amount,
                'date'            => $date,
                'balance'         => $newBalance,
                'pertain_to'      => 'voucher',
                'branch_id'       => $branchID,
                'created_at'      => date('Y-m-d H:i:s'),
                'created_by'      => get_loggedin_user_id()
            ];
            
            $this->db->insert('transactions', $transactionData);
            $transactions_id = $this->db->insert_id();
            
            if (!$transactions_id) {
                throw new Exception('Failed to record transaction');
            }
            
            // Update account balance
            $this->db->where('id', $accountID)
                    ->update('accounts', [
                        'balance' => $newBalance,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            
            if ($this->db->affected_rows() === 0) {
                throw new Exception('Failed to update account balance');
            }
            
            // Create voucher record
            $voucherData = [
                'transactions_id' => $transactions_id,
                'voucher_head_id' => $voucher_headID,
                'type'            => 'income',
                'amount'          => $amount,
                'date'            => $date,
                'branch_id'       => $branchID,
                'created_at'      => date('Y-m-d H:i:s'),
                'created_by'      => get_loggedin_user_id()
            ];
            
            $this->db->insert('voucher', $voucherData);
            $voucher_id = $this->db->insert_id();
            
            if (!$voucher_id) {
                throw new Exception('Failed to create voucher');
            }
            
            // Commit the transaction
            $this->db->trans_commit();
            
            return $voucher_id;
            
        } catch (Exception $e) {
            // Rollback the transaction on error
            $this->db->trans_rollback();
            log_message('error', 'Transaction failed: ' . $e->getMessage());
            return false;
        }
    }
}
