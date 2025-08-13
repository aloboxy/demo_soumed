<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Image_model extends CI_Model {

    private $table = 'image_settings';

    public function __construct() {
        parent::__construct();
    }

    /**
     * Get image path by key
     */
    public function get_image($key) {
        $this->db->where('image_key', $key);
        $result = $this->db->get($this->table)->row();
        return $result ? $result->image_path : '';
    }

    /**
     * Save or update an image path
     */
    public function save_image($key, $path) {
        $this->db->where('image_key', $key);
        $exists = $this->db->get($this->table)->row();
        
        $data = [
            'image_key' => $key,
            'image_path' => $path
        ];
        
        if ($exists) {
            $this->db->where('id', $exists->id);
            return $this->db->update($this->table, $data);
        } else {
            return $this->db->insert($this->table, $data);
        }
    }

    /**
     * Get all images
     */
    public function get_all_images() {
        $result = $this->db->get($this->table)->result_array();
        $images = [];
        foreach ($result as $row) {
            $images[$row['image_key']] = $row['image_path'];
        }
        return $images;
    }
}
