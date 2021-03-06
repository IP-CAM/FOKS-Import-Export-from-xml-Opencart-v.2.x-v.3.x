<?php

class ControllerToolFoks extends Controller
{
    private const LOG_FOLDER = 'view/javascript/app/logs/';
    private const DIST_FOLDER = '/admin/view/javascript/app/dist/';
    
    /**
     * @var array
     */
    private static $categoreis = [];
    
    /**
     * @var array
     */
    private $error = [];
    
    /**
     * Return settings for admin panel
     *
     */
    public function index()
    {
        set_time_limit(0);
        $this->document->addScript(self::DIST_FOLDER.'scripts/vue.js');
        $this->document->addStyle(self::DIST_FOLDER.'styles/vue.css');
        $this->document->setTitle('FOKS import/Export');
        $version = version_compare(VERSION, '3.0.0', '>=');
        
        self::createImgFolder();
        
        $this->load->model('tool/foks');
        
        $data['heading_title'] = 'FOKS import/Export';
        
        if (isset($this->session->data['error'])) {
            $data['error_warning'] = $this->session->data['error'];
            unset($this->session->data['error']);
        } elseif (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }
        
        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            
            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }
        
        if ( ! $version) {
            $token     = $this->session->data['token'];
            $token_str = 'token';
        } else {
            $token     = $this->session->data['user_token'];
            $token_str = 'user_token';
        }
        
        $data['breadcrumbs'] = [];
        
        $data['breadcrumbs'][] = [
            'text' => 'Home',
            'href' => $this->url->link('common/dashboard', "{$token_str}=".$token, 'SSL'),
        ];
        
        $data['breadcrumbs'][] = [
            'text' => 'FOKS',
            'href' => $this->url->link('tool/backup', "{$token_str}=".$token, 'SSL'),
        ];
        
        $file = str_replace("&amp;", '&', $this->model_tool_foks->getSetting('foks_import_url'));
        
        $foks_settings['foks'] = [
            'import'   => $file,
            'img'      => (boolean)$this->model_tool_foks->getSetting('foks_img'), //import with img
            'logs_url' => self::LOG_FOLDER,
            'update'   => '',
            'token'    => $token,
            'version3' => $version,
        ];
        
        file_put_contents(DIR_APPLICATION.self::LOG_FOLDER.'total.json', 0);
        file_put_contents(DIR_APPLICATION.self::LOG_FOLDER.'current.json', 0);
        
        $data['local_vars'] = self::LocalVars($foks_settings);
        
        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');
        
        if ( ! $version) {
            $this->response->setOutput($this->load->view('tool/foks.tpl', $data));
        } else {
            $this->response->setOutput($this->load->view('tool/foks', $data));
        }
    }
    
    /**
     * @param $file
     *
     * @return array|\SimpleXMLElement
     */
    public function parseFile($file)
    {
        $xmlstr = file_get_contents($file);
        $xml    = new \SimpleXMLElement($xmlstr);
        
        return [
            'categories' => self::parseCategories($xml->shop->categories),
            'products'   => $this->parseProducts($xml->shop->offers),
        ];
    }
    
    public function parseFileCategories($file)
    {
        $xmlstr = file_get_contents($file);
        $xml    = new \SimpleXMLElement($xmlstr);
        
        return self::parseCategories($xml->shop->categories);
    }
    
    public function parseFileProducts($file)
    {
        $xmlstr = file_get_contents($file);
        $xml    = new \SimpleXMLElement($xmlstr);
        
        return $this->parseProducts($xml->shop->offers);
    }
    
    /**
     * @param $categories
     *
     * @return array
     */
    public static function parseCategories($categories)
    {
        $categoriesList   = [];
        $data             = $categories->category;
        self::$categoreis = [];
        
        foreach ($data as $category) {
            $categoryName     = (string)$category;
            $categoriesList[] = [
                'parent_id'   => (int)$category['parentId'],
                'name'        => trim(htmlspecialchars($categoryName, ENT_QUOTES)),
                'id'          => (string)$category['id'],
                'parent_name' => '',
                'store_id'    => 0,
                'column'      => 0,
                'status'      => 1,
                'noindex'     => 0,
                'sort_order'  => 1,
            ];
        }
        
        $categories_result = [];
        
        foreach ($categoriesList as $item) {
            $item['parent_name'] = self::getParentCatName($categoriesList, $item['parent_id']);
            $categories_result[] = $item;
            self::$categoreis[]  = $item;
        }
        
        return $categories_result;
    }
    
    /**
     * @param $categoriesList
     * @param $parent_id
     * @param bool $id
     *
     * @return string
     */
    public static function getParentCatName($categoriesList, $parent_id, $id = false)
    {
        $cat_name = '';
        
        foreach ($categoriesList as $cat) {
            if ((int)$cat['id'] === $parent_id) {
                $cat_name = $cat['name'];
                break;
            }
            
            if ($id && (int)$cat['id'] === $id) {
                $cat_name = $cat['name'];
            }
        }
        
        return $cat_name;
    }
    
    /**
     * Convert from xml to array
     *
     * @param $offers
     *
     * @return array
     */
    public function parseProducts($offers)
    {
        $this->load->model('tool/foks');
        $count  = count($offers->offer);
        $result = [];
        
        for ($i = 0; $i < $count; $i++) {
            $offer = $offers->offer[$i];
            
            $product_images = [];
            $attributes     = [];
            $thumb_product  = '';
            $isMainImageSet = false;
            
            
            foreach ($offer->picture as $image) {
                if ( ! $isMainImageSet) {
                    $thumb_product  = $image;
                    $isMainImageSet = true;
                } else {
                    $product_images[] = $image;
                }
            }
            
            $productName = (string)$offer->name;
            
            if ( ! $productName) {
                if (isset($offer->typePrefix)) {
                    $productName = $offer->typePrefix.' '.$offer->model;
                } else {
                    $productName = (string)$offer->model;
                }
            }
            
            if (isset($offer->param) && ! empty($offer->param)) {
                $params = $offer->param;
                
                foreach ($params as $param) {

                    if ($param && isset($param['name'])) {
                        $attr_name  = (string)$param['name'];
                        $attr_value = (string)$param;
                        
                        $attributes[] = [
                            'name'  => htmlspecialchars($attr_name, ENT_QUOTES),
                            'value' => htmlspecialchars($attr_value, ENT_QUOTES),
                        ];
                    }
                }
            }
            
            $categoryName        = isset($offer->category) ? (string)$offer->category : '';
            $vendor              = isset($offer->vendor) ? (string)$offer->vendor : '';
            $id_category         = isset($offer->categoryId) ? (string)$offer->categoryId : 0;
            $product_description = isset($offer->description) ? (string)$offer->description : '';
            $category_name       = isset($categoryName) ? htmlspecialchars($categoryName, ENT_QUOTES) : '';
            $manufacturer        = isset($vendor) ? htmlspecialchars($vendor, ENT_QUOTES) : '';
            $price_old           = isset($offer->price_old) ? (float)$offer->price_old : '';
            
            if (empty($category_name)) {
                $category_name = self::searchCatName($id_category);
            }
            
            $data = [
                'name'            => htmlspecialchars($productName),
                'price'           => isset($offer->price) ? (float)$offer->price : '',
                'price_old'       => $price_old,
                'quantity'        => (isset($offer->stock_quantity)) ? (int)$offer->stock_quantity : 0,
                'model'           => (string)$offer['id'],
                'sku'             => isset($offer->vendorCode) && ! empty($offer->vendorCode) ? (string)$offer->vendorCode : (string)$offer['id'],
                'category'        => $category_name,
                'category_id'     => $this->getCategoryId($category_name),
                'parent_category' => '',
                'description'     => ! empty($product_description) ? html_entity_decode($product_description) : '',
                'image'           => $thumb_product,
                'images'          => $product_images,
                'date_available'  => date('Y-m-d'),
                'manufacturer_id' => $this->getManufacturerId($manufacturer),
                'manufacturer'    => $manufacturer,
                'status'          => 1,
                'attributes'      => $this->model_tool_foks->addAttributes($attributes),
            ];

            $result[$i] = $data;
        }
        
        return $result;
    }
    
    /**
     * @param $file
     *
     * @return array
     */
    public function importData($file)
    {
        $this->load->model('tool/foks');
        $categories = $this->parseFileCategories($file);
        
        try {
            $this->model_tool_foks->addCategories($categories);
        } catch (\Exception $e) {
            file_put_contents(DIR_APPLICATION.self::LOG_FOLDER.'error.json', $e->getMessage());
        }
        
        $products      = $this->parseFileProducts($file);
        $total_product = count($products);
        
        file_put_contents(DIR_APPLICATION.self::LOG_FOLDER.'total.json', $total_product);
        
        try {
            $this->model_tool_foks->addProducts($products);
        } catch (\Exception $e) {
            file_put_contents(DIR_APPLICATION.self::LOG_FOLDER.'error.json', $e->getMessage());
        }
        
        return $products;
    }
    
    /**
     * Scripts for admin panel
     *
     * @param $data
     *
     * @return string
     */
    public static function LocalVars($data)
    {
        $html = '';
        
        foreach ($data as $key => $value) {
            $html .= "<script>";
            if ( ! is_string($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $value = "'$value'";
            }
            
            $html .= "window.{$key} = {$value};"."\n";
            $html .= "</script>";
        }
        
        return $html;
    }
    
    /**
     * Save settings in admin panel
     *
     */
    public function ajaxSaveSettings()
    {
        $post = $this->request->post;
        
        $this->load->model('tool/foks');
        
        $img_val = $post['img'] === 'false' ? '0' : '1';
        
        $this->model_tool_foks->editSetting('foks_img', $img_val);
        $this->model_tool_foks->editSetting('foks_import_url', $post['import']);
        
        $json = $post;
        $this->response->addHeader('Content-Type: application/json');
        
        try {
            $this->response->setOutput(json_encode($json, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            file_put_contents(DIR_APPLICATION.self::LOG_FOLDER.'settings_error.json', $e->getMessage());
        }
    }
    
    /**
     * Import products from xml
     *
     */
    public function ajaxImportFoks()
    {
        $this->load->model('tool/foks');
        
        $file_x = $this->model_tool_foks->getSetting('foks_import_url');
        $file   = str_replace("&amp;", '&', $file_x);
        
        $data = [];
        
        if ($file) {
            $xml = file_get_contents($file);
            file_put_contents(DIR_APPLICATION.self::LOG_FOLDER.'foks_import.xml', $xml);
            $file_path = DIR_APPLICATION.self::LOG_FOLDER.'foks_import.xml';
            
            try {
                $data = [
                    'success' => true,
                    'message' => 'ok',
                    'data'    => $this->importData($file_path),
                ];
            } catch (\Exception $e) {
                file_put_contents(DIR_APPLICATION.self::LOG_FOLDER.'error.json', $e->getMessage());
                $data = ['message' => $e->getMessage()];
            }
        }
        
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($data));
    }
    
    /**
     * Get manufacturer id
     *
     * @param $name
     *
     * @return false|mixed
     */
    public function getManufacturerId($name)
    {
        if ( ! empty($name)) {
            $this->load->model('tool/foks');
            $data         = [
                'name'                     => $name,
                'sort_order'               => 1,
                'noindex'                  => 1,
                'manufacturer_description' => '',
            ];
            $manufacturer = $this->model_tool_foks->isManufacturer($data['name']);
            
            return $manufacturer['manufacturer_id'] ?? $this->model_tool_foks->addManufacturerImport($data);
        }
        
        return false;
    }
    
    /**
     * Get category id
     *
     * @param $name
     *
     * @return false|int
     */
    public function getCategoryId($name)
    {
        if ( ! empty($name)) {
            $this->load->model('tool/foks');
            
            $id          = false;
            $category_id = $this->model_tool_foks->isCategory($name);
            
            if ( ! empty($category_id)) {
                $id = (int)$category_id['category_id'];
            }
            
            return $id;
        }
        
        return false;
    }
    
    /**
     * Create image folder
     *
     */
    public static function createImgFolder()
    {
        $dir = DIR_IMAGE.'catalog/image_url';
        
        if ( ! file_exists($dir)) {
            if ( ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }
        }
    }
    
    /**
     * Search category name from id
     *
     * @param $cat_id
     * @param false $parent_id
     *
     * @return mixed|string
     */
    public static function searchCatName($cat_id, $parent_id = false)
    {
        $categories = self::$categoreis;
        $result     = '';
        foreach ($categories as $item) {
            if ($item['id'] == $cat_id) {
                $result = $item['name'];
            }
        }
        
        return $result;
    }
}
