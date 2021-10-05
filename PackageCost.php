<?php

namespace common\models;

use common\components\MyDebug;
use Yii;
use yii\base\BaseObject;
use yii\helpers\ArrayHelper;

/**
 * This is the model class for table "package_cost".
 *
 * @property int $id
  * @property int $width
 * @property int $height
 * @property int $depth
 * @property float $cost
 * @property int $quantity
 *
 * @property Package[] $packages
 * @property ProductGroup $productGroup
 */
class PackageCost extends \yii\db\ActiveRecord
{

//full/sqm/range/unit
	const PRICING_TYPE_FULL = 'full';

	const PRICING_TYPE_SQM = 'sqm';
	const PRICING_TYPE_RANGE = 'range';
	const PRICING_TYPE_UNIT = 'unit';
	const PRICING_TYPE_NOCOST = 'nocost';


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%package_cost%}}';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['cost'],'default', 'value' => 0],
            [['cost'], 'number'],
			[['quantity'], 'string', 'max' => 100],
			[['width', 'height'], 'filter', 'filter' => function ($value) {
//фильтр на входящий диапазогн, чтоб хорошо лег в БД
        			$arrays=preg_split("/[,;:]+/",str_replace(['(',')','{','}','[',']',' '],'',trim($value)));
        			if(count($arrays)==2){
        				return '['.implode(',',$arrays).')';
					}elseif(count($arrays)==1){
        				array_unshift($arrays,'0');
						return '['.implode(',',$arrays).')';
					}
        			return 'no Valid';
			}],
            [['package_id'], 'exist', 'skipOnError' => true, 'targetClass' => Package::className(), 'targetAttribute' => ['package_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('common', 'ID'),
            'width' => Yii::t('backend', 'Width'),
            'height' => Yii::t('backend', 'Height'),
            'cost' => Yii::t('backend', 'Cost'),
            'quantity' => Yii::t('backend', 'Quantity'),
        ];
    }

	public static function pricingTypes()
	{
		return [

			self::PRICING_TYPE_FULL => Yii::t('backend', 'Full Size'), //полный размер изделия
			self::PRICING_TYPE_RANGE=>Yii::t('backend', 'Range in Length'), //диапазон в длине для плит берем из колонки width
			self::PRICING_TYPE_SQM=>Yii::t('backend', 'Fixed Cost'), //фикс стоимость за м.кв
			self::PRICING_TYPE_UNIT=>Yii::t('backend', 'By Quantity'), //по количеству
			self::PRICING_TYPE_NOCOST=>Yii::t('backend', 'No Cost') //по количеству


		];
	}

	public static function getItemPrice($id){
		return ArrayHelper::getValue(self::pricingTypes(),$id);
	}

	/**
	 * @return \yii\db\ActiveQuery
	 */
	public function getPackage()
	{
		return $this->hasMany(Package::className(), ['id' => 'package_id']);
	}


}
