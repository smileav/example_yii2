<?php

namespace common\models;

use common\components\MyDebug;
use Yii;
use common\models\query\MultilingualQuery;
use omgdef\multilingual\MultilingualBehavior;

/**
 * This is the model class for table "shipping_custom_price_rules".
 *
 * @property int $id
 * @property int $city_type_id
 * @property int $shipping_type_id
 * @property int $product_group_id
 * @property int|null $product_quantity
 * @property int|null $value
 * @property string $type_value
 * @property string $code
 * @property int|null $delivery_for
 * @property string $require_product_group_ids
 *
 * @property ProductGroup $productGroup
 * @property ShippingType $shippingType
 */
class ShippingCustomPriceRules extends \yii\db\ActiveRecord
{

	public $cost=0;
	public $message='';
	public $available=true;
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'shipping_custom_price_rules';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['city_type_id', 'shipping_type_id', 'product_group_id'], 'required'],
            [['city_type_id', 'shipping_type_id', 'product_group_id', 'product_quantity',  'delivery_for'], 'default', 'value' => null],
            [['city_type_id', 'shipping_type_id', 'product_group_id', 'product_quantity',  'delivery_for'], 'integer'],
			[['is_active', 'integer'],'default', 'value' =>1],
			[['is_active'], 'integer'],
			[['value'], 'default', 'value' => 0.00],
			[['value'], 'number'],
			[['type_value', 'code', 'require_product_group_ids'], 'string', 'max' => 12],
            [['product_group_id'], 'exist', 'skipOnError' => true, 'targetClass' => ProductGroup::className(), 'targetAttribute' => ['product_group_id' => 'id']],
            [['shipping_type_id'], 'exist', 'skipOnError' => true, 'targetClass' => ShippingType::className(), 'targetAttribute' => ['shipping_type_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'city_type_id' => 'City Type ID',
            'shipping_type_id' => 'Shipping Type ID',
            'product_group_id' => 'Product Group ID',
            'product_quantity' => 'Product Quantity',
            'value' => 'Value',
            'type_value' => 'Type Value',
            'code' => 'Code',
            'delivery_for' => 'Delivery For',
            'require_product_group_ids' => 'Require Product Group Ids',
        ];
    }

    /**
     * Gets query for [[ProductGroup]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getProductGroup()
    {
        return $this->hasOne(ProductGroup::className(), ['id' => 'product_group_id']);
    }

    /**
     * Gets query for [[ShippingType]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getShippingType()
    {
        return $this->hasOne(ShippingType::className(), ['id' => 'shipping_type_id']);
    }

    public function getShippingCost($form){

		$shippingId=(isset($form['shipping_data']['shipping_type_id'])?$form['shipping_data']['shipping_type_id']:0);
		$city_id=(isset($form['shipping_data']['city_id'])?$form['shipping_data']['city_id']:0);
		$filial_id=(isset($form['shipping_data']['filial_id'])?$form['shipping_data']['filial_id']:0);
		$street_id=(isset($form['shipping_data']['street_id'])?$form['shipping_data']['street_id']:0);
		$shippingFor=(isset($form['shipping_data']['delivery_for'])?$form['shipping_data']['delivery_for']:1);
		$service_type=(isset($form['shipping_data']['service_type'])?$form['shipping_data']['service_type']:'');

		$orderId=(Yii ::$app->request->post('orderId'))?Yii ::$app->request->post('orderId'):Yii ::$app->request->get('orderId');
		if($orderId) {
			$order = Order::find()->where(['id' => $orderId])->one();
		}else {
			$order = Order::getOrder();
		}
		//$order=Order::getOrder();

		$where_like=[];
		$productByGroup=[];

		//подготовка массивов, считаем товары в круппах
		foreach ($order->productsAndGroup as $group) {
			if (isset($productByGroup[$group['id']])) {
				$productByGroup[$group['id']]['quantity'] += $group['quantity'];
				array_push($productByGroup[$group['id']]['products'], $group['product_id']);
			} else {
				$productByGroup[$group['id']]['quantity'] = $group['quantity'];
				$productByGroup[$group['id']]['products'][] = $group['product_id'];
			}
		}

		//готовим запрос для фильтрации по обязательным группам в заказе
		$group_array=array_keys($productByGroup);
		foreach($group_array as $g){
			$where_like[] = "require_product_group_ids ='" . $g . "'";
		}
		$where_like[] = "require_product_group_ids =''";

		if($shippingFor==2) { //для доставки РОСТ
			foreach ($productByGroup as $group_id => $group) { //по каждой группе товаров проверяем условия доставки
				$shipping_cost_rules_model = self::find()->andWhere("product_group_id IN('" . $group_id . "')")
					->andWhere(['shipping_type_id' => $shippingId])
					->andWhere('(case when (
								select count(*) from "shipping_custom_price_rules" where city_type_id=' . (int)$city_id . '
								and shipping_type_id=' . (int)$shippingId . ' and is_active=true)>0 
		  					then city_type_id=' . (int)$city_id . ' else city_type_id=0 end 
							) 
						')
					->andWhere(['delivery_for' => $shippingFor])
					->andWhere(implode(' or ', $where_like))
					->andWhere('product_quantity<=' . $group['quantity'])
					->andWhere(['is_active'=>true])
					->orderBy(' product_quantity desc, value Asc ')
					->one();

				//MyDebug::debugsql($shipping_cost_rules_model);exit();

				if ($shipping_cost_rules_model) { //если есть условия -  считаем стоимость
					switch ($shipping_cost_rules_model->type_value) {
						case "C":
							$this->cost += $shipping_cost_rules_model->value;
							break;
						case "P":
							$cost = 0;
							foreach ($order->items as $product) {
								foreach ($group['products'] as $p_group) {
									if ($product->product_id == $p_group && $group_id == $shipping_cost_rules_model->product_group_id) {
										$cost += $product->total;
									}
								}
							}
							foreach ($order->itemsNonStandard as $product) {
								foreach ($group['products'] as $p_group) {
									if ($product->product_id == $p_group && $group_id == $shipping_cost_rules_model->product_group_id) {
										$cost += $product->total;
									}
								}
							}
							$this->cost += $cost * $shipping_cost_rules_model->value / 100;
							//$rule->product_group_id

							break;
						case "A":
							$this->message="Ошибка в условиях доставки "; //если все правильно выгружено из 1С сюда никогда не попадем
							break;
					}
				} else {
					$this->message = 'Такой способ доставки возможен только по согласованию с менеджером, стоимость считается отдельно.';
					$this->available=false;
				}
			}
		}elseif($filial_id||$street_id){ // расчет по АПИ при доставке за счет заказчика

			//заполняем минимум данных остальная магия в AbstractShipping
			$shippingTypeModel=ShippingType::find()->where(['is_active' => true])->andWhere(['id'=>$shippingId])->one();
			$shippingTypeModel->getShipping()->product_total=$order->fullPriceProduct;
			$shippingTypeModel->getShipping()->cash_on_delivery=$order->fullPriceProduct;
			$shippingTypeModel->getShipping()->product_weight=$order->totalWeight;
			if($service_type) { //схемы доставки склад-склад, склад-дверь -  может из формы не прийти, по этому проверяем
				$shippingTypeModel->getShipping()->deliveryScheme = $service_type;
			}
			$shippingTypeModel->getShipping()->setCityRefById($city_id);
			$shippingTypeModel->getShipping()->setFilialRefById(($filial_id)?$filial_id:$street_id);
			$result_api=$shippingTypeModel->getShipping()->getApi();
			$this->cost=$result_api['cost'];
			$this->message=$result_api['message'];
		}
		$this->shipping_type_id=$shippingId;

		$this->cost=round($this->cost,4);
		return $this;
	}
}
