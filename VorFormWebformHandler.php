<?php

namespace Drupal\buyit_webform_handler\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * VOR Webform Handler
 *
 * @WebformHandler(
 *   id = "webform_update_vor_status",
 *   label = @Translation("VOR Webform Handler"),
 *   category = @Translation("Entity Update"),
 *   description = @Translation("Create and updated BuyIT products after VOR form submission."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */

class VorFormWebformHandler extends WebformHandlerBase
{
    protected $pars_ent_array = [];

    // Sort products array (order: Received, Missing, Damaged) for "Process of Elimination" logic
    // To be used on the $pars_ent_array
    public function sortProductParsEntArray($a, $b) {
        if ($a->get('field_product_status')->value == $b->get('field_product_status')->value) {
            return 0;
        } else if ($a->get('field_product_status')->value == 'Received' && $b->get('field_product_status')->value != "Received") {
            return -1;
        } else if ($a->get('field_product_status')->value != 'Received' && $b->get('field_product_status')->value == "Received") {
            return 1;
        } else if ($a->get('field_product_status')->value == 'Missing' && $b->get('field_product_status')->value == "Damaged/Non-functional") {
            return -1;
        } else if ($a->get('field_product_status')->value == 'Damaged/Non-functional' && $b->get('field_product_status')->value == "Missing") {
            return 1;
        }
    }
    // String sanitization needed for differences in ASCII: some spaces (/\xA0/) & hidden/invisible Â (/\xC2/)
    public function sanitizeProductName($name) {
        $name = trim($name);
        $name = preg_replace('/\xC2/', '', $name);
        $name = preg_replace('/\xA0/', ' ', $name);
        return $name;
    }

    public function getProdEntityId($product_name) {
        $product_id = '';
        $product_name = $this->sanitizeProductName($product_name);
        switch($product_name) {
            case 'Dell 3520 - Standard (Laptop Only)':
                $product_id = 1008;
                break;
            case 'Dell 3520 - Developer (Laptop Only)':
                $product_id = 1009;
                break;
            case 'Microsoft Surface Laptop 4 (Laptop Only)':
                $product_id = 1010;
                break;
            case 'Microsoft Surface Book 3 (Laptop Only)':
                $product_id = 1011;
                break;
            case 'Microsoft Surface Laptop 4 (Bundle)':
                $product_id = 1016;
                break;
            case 'Logitech Webcam C903e':
                $product_id = 1020;
                break;
            case 'Dell 3520 - Standard (Bundle)':
                $product_id = 1014;
                break;
            case 'Dell 3520 - Developer (Bundle)':
                $product_id = 1015;
                break;
            case 'Microsoft Surface Book 3 (Bundle)':
                $product_id = 1017;
                break;
            case 'Microsoft Surface Pro 7+ (Bundle)':
                $product_id = 1057;
                break;
            case 'Microsoft Surface Pro 7+ (Laptop Only)':
                $product_id = 1058;
                break;
            default:
                $product_id = null;
                break;                 
        }
        return $product_id;
    } // End getProdEntID method

    public function textareaToArray($list) {   
        $list_array = explode("\n", $list);
        $list_array = array_filter($list_array, function($array_element){
            return $array_element != "";
        });
        foreach($list_array as &$element) {
            // remove quantity from product name (e.g. "1 x")
            $element = substr($element, strpos($element, 'x') + 2);
        };
        return $list_array;
    }

   /**
     * {@inheritdoc}
     * Create paragraphs after initial VOR submission 
     */

    public function vorCreateProducts(
        $node,
        $product,
        // One of 3 reference revision fields (Dell, Logitech, Microsoft)
        $product_brand_paragraphs,
        $product_status,
        $reported_missing_date,
        $reported_recd_date
    ) {
        $paragraph = Paragraph::create([
            'type' => 'buyit_product',
        ]);
        $paragraph->field_product_reference[] = ['target_id' => $product];
        $paragraph->set('field_product_status', $product_status);
        $paragraph->set('field_reported_missing_date', $reported_missing_date);
        $paragraph->set('field_received_date', $reported_recd_date);
        $paragraph->save();

        // Grab any existing paragraphs from the node, and add this one 
        $current = $node->get($product_brand_paragraphs)->getValue();
        $current[] = array(
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
        );
        $node->set($product_brand_paragraphs, $current);
    }

    public function vorUpdateProducts($webform_array, $loop_product_status) {
        foreach($webform_array as $webform_product) {
            $webform_product_id = $this->getProdEntityId($webform_product);
            $active_index = 0;
            
            while( (count($this->pars_ent_array) >= 1) ) {
                $current_par_product_id = intval($this->pars_ent_array[$active_index]->get('field_product_reference')->target_id);
                $current_par_status = $this->pars_ent_array[$active_index]->get('field_product_status')->value;

                    // MISSING ITEMS LOGIC
                    if ($loop_product_status == "missing") {
                        if ($webform_product_id == $current_par_product_id) {
                            if ($current_par_status == 'Missing') {
                                // Remove Pars Ent Element fr. Array  and Reset Index # using array_values
                                unset($this->pars_ent_array[$active_index]); 
                                $this->pars_ent_array = array_values($this->pars_ent_array);
                                break;
                            } else if ($current_par_status == 'Damaged/Non-functional' || $current_par_status == 'Received') {
                                // There shouldn't be any other statuses besides these two (above) if we reach this point.
                                // But check just in case...
                                $active_index++;
                            }
                        } else {
                            $active_index++;
                        }
                    } // END MISSING ITEMS LOGIC

                    // RECEIVED ITEMS LOGIC
                    if ($loop_product_status == "received") {
                        if ($webform_product_id == $current_par_product_id) {
                            if($current_par_status == 'Received') {
                                // Remove Pars Ent Element fr. Array  and Reset Index # using array_values
                                unset($this->pars_ent_array[$active_index]); 
                                $this->pars_ent_array = array_values($this->pars_ent_array);
                                break;
                            } else if ($current_par_status == 'Missing') {
                                // Update Current paragraph status from Missing to Received
                                $this->pars_ent_array[$active_index]->set('field_product_status', 'Received');
                                $this->pars_ent_array[$active_index]->set('field_received_date', date('Y-m-d'));
                                $this->pars_ent_array[$active_index]->save();            
                                // Remove Pars Ent Element fr. Array and Reset Index # using array_values
                                unset($this->pars_ent_array[$active_index]); 
                                $this->pars_ent_array = array_values($this->pars_ent_array);
                                break;
                            } else if ($current_par_status == 'Damaged/Non-functional') {
                                $active_index++;
                            }
                        } else {
                            $active_index++;
                        }
                    } // END RECEIVED ITEMS LOGIC

                    // DAMAGED ITEMS LOGIC
                    if ($loop_product_status == "damaged") {
                        if ($webform_product_id == $current_par_product_id) {
                            if ($current_par_status == 'Missing') {
                                // Update Current paragraph status from Missing to Received
                                $this->pars_ent_array[$active_index]->set('field_product_status', 'Damaged/Non-functional');
                                $this->pars_ent_array[$active_index]->set('field_received_date', date('Y-m-d'));
                                $this->pars_ent_array[$active_index]->save();            
                                // Remove Pars Ent Element fr. Array and Reset Index # using array_values
                                unset($this->pars_ent_array[$active_index]); 
                                $this->pars_ent_array = array_values($this->pars_ent_array);
                                break;
                            } else if ($current_par_status == 'Damaged/Non-functional') {
                                // Remove Pars Ent Element fr. Array  and Reset Index # using array_values
                                unset($this->pars_ent_array[$active_index]); 
                                $this->pars_ent_array = array_values($this->pars_ent_array);
                                break;
                            } else if ($current_par_status == 'Received') {
                                // This test (above) should never be true. All received items should have
                                // already been unset from the $pars_ent_array. But just in case...
                                $active_index++;
                            }
                        } else {
                            $active_index++;
                        }
                    } // END DAMAGED ITEMS LOGIC

            } // end WHILE
        } //end FOREACH
    } // end method vorUpdateProducts()

    /**
     * {@inheritdoc}
     */
    public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
        // Get submission data
        $values = $webform_submission->getData();
        $webform = $webform_submission->getWebform();
        $webform_name = $webform->id();
        $node_id = (int) $values['govt_entity_id'];

        // Make sure node_id is set (not NULL)
        if(!isset($node_id)) {
            return;
        }

        // Load govt_entity_node
        $entity_to_update = Node::load($node_id);

        // Set field values based on form being used.
        switch ($webform_name) {
            case 'logitech_vor_form':
                $started_vor_field = 'field_started_logi_vor_form';
                $completed_vor_field = 'field_completed_logi_vor_form';
                $missing_items_field = 'field_missing_logitech_items';
                $damaged_items_field = 'field_broken_logitech_items';
                $product_brand_paragraphs = 'field_logi_products_paragraphs';
                break;
            case 'microsoft_vor_form':
                $started_vor_field = 'field_started_mic_vor_form';
                $completed_vor_field = 'field_completed_mic_vor_form';
                $missing_items_field = 'field_missing_microsoft_items';
                $damaged_items_field = 'field_broken_microsoft_items';
                $product_brand_paragraphs = 'field_micr_products_paragraphs';
                break;
            case 'dell_vor_form':
                $started_vor_field = 'field_started_dell_vor_form';
                $completed_vor_field = 'field_completed_dell_vor_form';
                $missing_items_field = 'field_missing_dell_items_';
                $damaged_items_field = 'field_broken_dell_items';
                $product_brand_paragraphs = 'field_dell_products_paragraphs';
                break;
            default:
                die();
        }

        // Create Arrays out of textarea values
        $missing_items = isset($values['missing_items_list']) ? $this->textareaToArray($values['missing_items_list']) : null;
        $damaged_items = isset($values['broken_items_list']) ? $this->textareaToArray($values['broken_items_list']) : null;
        $received_items = isset($values['received_good_list']) ? $this->textareaToArray($values['received_good_list']) : null;

        // Get value of products paragraph field for current product brand/type.
        $current_par_products_field = $entity_to_update->get($product_brand_paragraphs)->getValue();
        
        // CREATE BuyIT Product paragraphs upon *** initial submission *** of VOR Form 
        if (count($current_par_products_field) === 0 && (isset($missing_items) || isset($damaged_items) || isset($received_items))) {

            // If initially missing items then update that field in the node.
            if (isset($missing_items) && count($missing_items) != 0) {
                $entity_to_update->set($missing_items_field, '1');
            }

            // If initially damaged items then update that field in the node.
            if (isset($damaged_items) && count($damaged_items) != 0) {
                $entity_to_update->set($damaged_items_field, '1');
            }            

            if (isset($missing_items)) {
                foreach($missing_items as $item) {
                    $product_id = $this->getProdEntityId($item);
                    $this->vorCreateProducts(
                        $entity_to_update, 
                        $product_id, 
                        $product_brand_paragraphs, 
                        'Missing',
                        date('Y-m-d'), 
                        null
                    );
                }
            }
            if (isset($damaged_items)) {
                foreach($damaged_items as $item) {
                    $product_id = $this->getProdEntityId($item);
                    $this->vorCreateProducts(
                        $entity_to_update, 
                        $product_id, 
                        $product_brand_paragraphs, 
                        'Damaged/Non-functional',
                        null,
                        date('Y-m-d')
                    );
                }
            }
            if (isset($received_items)) {
                foreach($received_items as $item) {
                    $product_id = $this->getProdEntityId($item);
                    $this->vorCreateProducts(
                        $entity_to_update, 
                        $product_id, 
                        $product_brand_paragraphs, 
                        'Received',
                        null,
                        date('Y-m-d')
                    );
                }
            }
            
            // Mark off "started VOR form" checkbox.
            $entity_to_update->set($started_vor_field, '1');
        } // End CREATE product

        // UPDATE BuyIT Product Paragraphs Upon *** Resubmission *** of VOR Form            
        if (count($current_par_products_field) > 0 && (isset($missing_items) || isset($damaged_items) || isset($received_items))) {
            
            // Create $pars_ent_array out of existing (in DB) BuyIT product "paragraphs".
            // Use array $current_par_products_field and load actual "paragraphs" objects (BuyIT products) based off of their target_id's

            foreach($current_par_products_field as $paragraph) {
                $current_product_par = Paragraph::load($paragraph['target_id']);
                $this->pars_ent_array[] = $current_product_par;
            }

            usort($this->pars_ent_array, array($this, 'sortProductParsEntArray'));

            // Process textarea arrays (Must be in this order: "missing", "received", "damaged")
            $loop_product_status = "missing";
            $this->vorUpdateProducts($missing_items, $loop_product_status);
        
            $loop_product_status = "received";
            $this->vorUpdateProducts($received_items, $loop_product_status);

            $loop_product_status = "damaged";
            $this->vorUpdateProducts($damaged_items, $loop_product_status);
        } // End UPDATE product

        // Refresh List of Pars saved in DB (needed for marking Completion Fields in next step)
        $current_par_products_field = $entity_to_update->get($product_brand_paragraphs)->getValue();
        
        // Mark Vor Completion Field only if all items on an entity are marked received (damaged or good). 
        if(count($current_par_products_field) >= 1) {
            $all_received = true;
            foreach($current_par_products_field as $paragraph) {
                $current_product_par = Paragraph::load($paragraph['target_id']);
                $received_status = $current_product_par->get('field_product_status')->getValue();
                if($received_status[0]['value'] != "Received" && $received_status[0]['value'] != "Damaged/Non-functional") {
                    $all_received = false;
                }
            }
            if ($all_received == true) {
                $entity_to_update->set($completed_vor_field, '1');
            }
        }

        // Save revised node
        $entity_to_update->save();

    } // end postSave method (which is run automatically when webform is submitted)

} // end VorFormWebformHandler Class
