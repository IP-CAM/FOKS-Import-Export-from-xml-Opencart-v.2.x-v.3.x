<?php

    class ControllerToolFoks extends Controller {
        
        const LOG_FOLDER = 'view/javascript/app/logs/';
        
        const DIST_FOLDER = '/admin/view/javascript/app/dist/';
        
        /**
         * @var array
         */
        private static $categoreis = [];
        
        /**
         * @var array
         */
        private $error = array();
        
        /**
         * Return settings for admin panel
         *
         */
        public function index() {
            $this->document->addScript( self::DIST_FOLDER . 'scripts/vue.js' );
            $this->document->addStyle( self::DIST_FOLDER . 'styles/vue.css' );
            $this->document->setTitle( 'FOKS import/Export' );
            $version = version_compare( VERSION, '3.0.0', '>=' );
            
            self::createImgFolder();
            
            $this->load->model( 'tool/foks' );
            
            $data['heading_title'] = 'FOKS import/Export';
            
            if ( isset( $this->session->data['error'] ) ) {
                $data['error_warning'] = $this->session->data['error'];
                
                unset( $this->session->data['error'] );
            } elseif ( isset( $this->error['warning'] ) ) {
                $data['error_warning'] = $this->error['warning'];
            } else {
                $data['error_warning'] = '';
            }
            
            if ( isset( $this->session->data['success'] ) ) {
                $data['success'] = $this->session->data['success'];
                
                unset( $this->session->data['success'] );
            } else {
                $data['success'] = '';
            }
            if ( !$version ) {
                $token     = $this->session->data['token'];
                $token_str = 'token';
            } else {
                $token     = $this->session->data['user_token'];
                $token_str = 'user_token';
            }
            
            $data['breadcrumbs'] = array();
            
            
            $data['breadcrumbs'][] = array(
                'text' => 'Home',
                'href' => $this->url->link( 'common/dashboard', "{$token_str}=" . $token, 'SSL' )
            );
            
            $data['breadcrumbs'][] = array(
                'text' => 'FOKS',
                'href' => $this->url->link( 'tool/backup', "{$token_str}=" . $token, 'SSL' )
            );
            
            $file = str_replace( "&amp;", '&', $this->model_tool_foks->getSetting( 'foks_import_url' ) );
            
            $foks_settings['foks'] = [
                'import'   => $file,
                'img'      => (boolean)$this->model_tool_foks->getSetting( 'foks_img' ), //import with img
                'logs_url' => self::LOG_FOLDER,
                'update'   => '',
                'token'    => $token,
                'version3' => $version,
            ];
            
            file_put_contents( DIR_APPLICATION . self::LOG_FOLDER . 'total.json', 0 );
            file_put_contents( DIR_APPLICATION . self::LOG_FOLDER . 'current.json', 0 );
            
            $data['local_vars'] = self::LocalVars( $foks_settings );
            
            $data['header']      = $this->load->controller( 'common/header' );
            $data['column_left'] = $this->load->controller( 'common/column_left' );
            $data['footer']      = $this->load->controller( 'common/footer' );
            
            if ( !$version ) {
                $this->response->setOutput( $this->load->view( 'tool/foks.tpl', $data ) );
            } else {
                $this->response->setOutput( $this->load->view( 'tool/foks', $data ) );
            }
        }
        
        
        /**
         * @param $file
         * @return array|\SimpleXMLElement
         */
        public function parseFile( $file ) {
            set_time_limit( 0 );
            $xmlstr = file_get_contents( $file );
            $xml    = new \SimpleXMLElement( $xmlstr );
            
            return [
                'categories' => self::parseCategories( $xml->shop->categories ),
                'products'   => $this->parseProducts( $xml->shop->offers )
            ];
        }
        
        
        public function parseFileCategories( $file ) {
            set_time_limit( 0 );
            $xmlstr = file_get_contents( $file );
            $xml    = new \SimpleXMLElement( $xmlstr );
            return self::parseCategories( $xml->shop->categories );
            
        }
        
        public function parseFileProducts( $file ) {
            set_time_limit( 0 );
            $xmlstr = file_get_contents( $file );
            $xml    = new \SimpleXMLElement( $xmlstr );
            return $this->parseProducts( $xml->shop->offers );
        }
        
        
        /**
         * @param $categories
         * @return array
         */
        public static function parseCategories( $categories ) {
            $categoriesList   = array();
            $data             = $categories->category;
            self::$categoreis = [];
            foreach ( $data as $category ) {
                $categoriesList[] = array(
                    'parent_id'   => (int)$category['parentId'],
                    'name'        => trim( (string)$category ),
                    'id'          => (string)$category['id'],
                    'parent_name' => '',
                    'store_id'    => 0,
                    'column'      => 0,
                    'status'      => 1,
                    'noindex'     => 0,
                    'sort_order'  => 1
                );
            }
            $categories_result = [];
            foreach ( $categoriesList as $item ) {
                $item['parent_name'] = self::getParentCatName( $categoriesList, $item['parent_id'] );
                $categories_result[] = $item;
                self::$categoreis[]  = $item;
            }
            
            return $categories_result;
        }
        
        /**
         * @param $categoriesList
         * @param $parent_id
         * @param bool $id
         * @return string
         */
        public static function getParentCatName( $categoriesList, $parent_id, $id = false ) {
            $cat_name = '';
            foreach ( $categoriesList as $cat ) {
                if ( (int)$cat['id'] === $parent_id ) {
                    $cat_name = $cat['name'];
                    break;
                } else {
                    if ( $id && (int)$cat['id'] === $id ) {
                        $cat_name = $cat['name'];
                    }
                }
                
            }
            return $cat_name;
        }
        
        /**
         * Convert from xml to array
         *
         * @param $offers
         * @return array
         */
        public function parseProducts( $offers ) {
            $this->load->model( 'tool/foks' );
            $n      = count( $offers->offer );
            $result = [];
            for ( $i = 0; $i < $n; $i++ ) {
                
                $offer = $offers->offer[ $i ];
                
                $product_images = [];
                $attributes     = [];
                $thumb_product  = '';
                $isMainImageSet = false;
                
                
                foreach ( $offer->picture as $image ) {
                    if ( !$isMainImageSet ) {
                        $thumb_product  = $image;
                        $isMainImageSet = true;
                    } else {
                        array_push( $product_images, $image );
                    }
                }
                
                $productName = (string)$offer->name;
                
                if ( !$productName ) {
                    if ( isset( $offer->typePrefix ) ) {
                        $productName = (string)$offer->typePrefix . ' ' . (string)$offer->model;
                    } else {
                        $productName = (string)$offer->model;
                    }
                }
                
                if ( isset( $offer->param ) ) {
                    $params = $offer->param;
                    
                    foreach ( $params as $param ) {
                        $attr_name  = (string)$param['name'];
                        $attr_value = (string)$param;
                        
                        $attributes[] = [
                            'name'  => $attr_name,
                            'value' => $attr_value
                        ];
                    }
                }
                
                $id_category         = (string)$offer->categoryId;
                $product_description = isset( $offer->description ) ? (string)$offer->description : '';
                $category_name       = isset( $offer->category ) ? (string)$offer->category : '';
                $manufacturer        = isset( $offer->vendor ) ? (string)$offer->vendor : '';
                $price_old           = isset( $offer->price_old ) ? (float)$offer->price_old : '';
                
                
                if ( empty( $category_name ) ) {
                    $category_name = self::searchCatName( $id_category );
                }
                
                $data = array(
                    'name'            => $productName,
                    'price'           => isset( $offer->price ) ? (float)$offer->price : '',
                    'price_old'       => $price_old,
                    'quantity'        => (isset( $offer->outlets->outlet['instock'] )) ? (int)$offer->outlets->outlet['instock'] : '999',
                    'model'           => (string)$offer['id'],
                    'sku'             => isset( $offer->vendorCode ) && !empty( $offer->vendorCode ) ? (string)$offer->vendorCode : (string)$offer['id'],
                    'category'        => $category_name,
                    'category_id'     => $this->getCategoryId( $category_name ),
                    'parent_category' => '',
                    'description'     => $product_description,
                    'image'           => $thumb_product,
                    'images'          => $product_images,
                    'date_available'  => date( 'Y-m-d' ),
                    'manufacturer_id' => $this->getManufacturerId( $manufacturer ),
                    'manufacturer'    => $manufacturer,
                    'status'          => '0',
                    'attributes'      => $this->model_tool_foks->addAttributes( $attributes ),
                );
                
                $result[ $i ] = $data;
                
            }
            return $result;
        }
        
        
        /**
         * @param $file
         * @return array
         */
        public function importData( $file ) {
            $this->load->model( 'tool/foks' );
            $categories = $this->parseFileCategories( $file );
            $this->model_tool_foks->addCategories( $categories );
            
            $products      = $this->parseFileProducts( $file );
            $total_product = count( $products );
            file_put_contents( DIR_APPLICATION . self::LOG_FOLDER . 'total.json', $total_product );
    
            try {
                $this->model_tool_foks->addProducts( $products );
            } catch (\Exception $e) {
                return [$e->getMessage()];
            }
            
            return $products;
        }
        
        /**
         * Scripts for admin panel
         *
         * @param $data
         * @return string
         */
        public static function LocalVars( $data ) {
            $html = '';
            
            foreach ( $data as $key => $value ) {
                $html .= "<script>";
                if ( !is_string( $value ) ) {
                    $value = json_encode( $value, JSON_UNESCAPED_UNICODE );
                } elseif ( is_string( $value ) ) {
                    $value = "'$value'";
                }
                
                $html .= "window.{$key} = {$value};" . "\n";
                $html .= "</script>";
            }
            return $html;
        }
        
        /**
         * Save settings in admin panel
         *
         */
        public function ajaxSaveSettings() {
            $post = $this->request->post;
            
            $this->load->model( 'tool/foks' );
            
            $img_val = $post['img'] === 'false' ? '0' : '1';
            
            $this->model_tool_foks->editSetting( 'foks_img', $img_val );
            $this->model_tool_foks->editSetting( 'foks_import_url', $post['import'] );
            
            $json = $post;
            $this->response->addHeader( 'Content-Type: application/json' );
            $this->response->setOutput( json_encode( $json ) );
        }
        
        /**
         * Import products from xml
         *
         */
        public function ajaxImportFoks() {
            $this->load->model( 'tool/foks' );
            
            $file_x = $this->model_tool_foks->getSetting( 'foks_import_url' );
            $file   = str_replace( "&amp;", '&', $file_x );
            
            $data = [];
            if ( $file ) {
                $xml = file_get_contents( $file );
                file_put_contents( DIR_APPLICATION . self::LOG_FOLDER . 'foks_import.xml', $xml );
                $file_path = DIR_APPLICATION . self::LOG_FOLDER . 'foks_import.xml';
                try {
                    $data  = $this->importData( $file_path );
                } catch (\Exception $e) {
                    $this->response->setOutput( json_encode( $e->getMessage() ) );
                }
            }
            
            $this->response->addHeader( 'Content-Type: application/json' );
            $this->response->setOutput( json_encode( $data ) );
        }
        
        /**
         * Get manufacturer id
         *
         * @param $name
         * @return false|mixed
         */
        public function getManufacturerId( $name ) {
            if ( !empty( $name ) ) {
                $this->load->model( 'tool/foks' );
                $data         = [
                    'name'                     => addslashes( $name ),
                    'sort_order'               => 1,
                    'noindex'                  => 1,
                    'manufacturer_description' => '',
                ];
                $manufacturer = $this->model_tool_foks->isManufacturer( $data['name'] );
                if ( isset( $manufacturer['manufacturer_id'] ) ) {
                    $id = $manufacturer['manufacturer_id'];
                } else {
                    $id = $this->model_tool_foks->addManufacturerImport( $data );
                }
                return $id;
            }
            return false;
        }
        
        /**
         * Get category id
         *
         * @param $name
         * @return false|int
         */
        public function getCategoryId( $name ) {
            if ( !empty( $name ) ) {
                $this->load->model( 'tool/foks' );
                
                $id          = false;
                $category_id = $this->model_tool_foks->isCategory( $name );
                
                if ( !empty( $category_id ) ) {
                    $id = (int)$category_id['category_id'];
                }
                
                return $id;
            } else {
                return false;
            }
        }
        
        /**
         * Create image folder
         *
         */
        public static function createImgFolder() {
            $dir = DIR_IMAGE . 'catalog/image_url';
            
            if ( !file_exists( $dir ) ) {
                mkdir( $dir, 0777, true );
            }
            
        }
        
        /**
         * Search category name from id
         *
         * @param $cat_id
         * @param false $parent_id
         * @return mixed|string
         */
        public static function searchCatName( $cat_id, $parent_id = false ) {
            $categories = self::$categoreis;
            $result     = '';
            foreach ( $categories as $item ) {
                if ( $item['id'] == $cat_id ) {
                    $result = $item['name'];
                }
            }
            
            return $result;
        }
        
    }
