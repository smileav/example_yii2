<?php

namespace frontend\modules\shop\widgets;

use common\helpers\MultilingualHelper;
use common\models\Category;
use common\models\CategoryLang;
use common\models\Decor;
use common\models\DecorType;
use common\models\DecorTypeLang;
use common\models\Product;
use common\models\ProductCategory;
use common\models\ProductProperty;
use common\models\Property;
use common\models\PropertyStaticValue;
use common\models\PropertyStaticValueLang;
use Yii;
use yii\base\Widget;
use yii\db\Query;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\caching\DbDependency;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use common\components\MyDebug;
use yii\httpclient\UrlEncodedParser;

class FilterWidget extends Widget
{
    /** @var Category */
    public $model = null;
    public $totalCount = 0;

    /**
     * @inheritDoc
     */
    public function run()
    {
        $priceRange = (new Query())->select([
            'MIN(floor(product.price)) AS min_price',
            'MAX(ceil(product.price)) AS max_price',
        ])
            ->from(['product', 'product_category'])
            ->where('product.id = product_category.product_id')
            ->andWhere(
                [
                    'product.is_active' => true,
                    'product_category.category_id' => $this->model->id,
                ]
            )->one();
        $priceValues = [(int)$priceRange['min_price'], (int)$priceRange['max_price']];

        $get = ArrayHelper::merge(Yii::$app->request->get(), Yii::$app->request->post());
        $properties = isset($get['property']) ? $get['property'] : [];
        if (isset($properties['price'])) {
            $priceValues = explode(',', $properties['price']);
        }

		$filters = [];

		//статические/виртуальные группы фильтров для статусов
		$filter_static['-2']=[
			'title'=>Yii ::t('frontend', 'Status'),
			'options'=>[],
			'options_class'=>'',
			'item_class'=>'',
			'sort_order'=>0,
			'status'=>0,
		];
		//блок популярных фильтров
		$filter_static['-4']=[
			'title'=>Yii ::t('frontend', 'Popular Filters'),
			'options'=>$this->getPopularOptions($this->model->popularFilters,$properties),
			'options_class'=>'',
			'item_class'=>'',
			'sort_order'=>0,
			'status'=>0,
		];
		$filter_static['-3']=[
			'title'=>Yii ::t('frontend', 'Price'),
			'options'=>[$this->render('filter_block_price',[
				'priceValues' => $priceValues,
				'priceMin' => (int)$priceRange['min_price'],
				'priceMax' => (int)$priceRange['max_price'],
			])],
			'options_class'=>'',
			'item_class'=>'prices-item',
			'sort_order'=>0,
			'status'=>0,
		];


		//декоры
		$filter_static['-1']=[
			'title'=>Yii::t('common', 'Decors'),
			'options'=>$this->getDecorsTypeList($properties),
			'options_class'=>'decors',
			'item_class'=>'',
			'sort_order'=>2,
			'child'=>[$this->render('filter_block_child_dropdown', [
						'title'=>Yii::t('frontend', 'Select Decor Code'),
						'options'=>$this->getDecorsList($properties),
						'class'=>'decor_code',
						'id'=>'select-decors'
					]
				)
			],
			'status'=>0,
		];
		//относим конкретные свойства в групу
		$custom_group['-2']=[112,147,210,211];// акции и новинки перенесем в группу статусы

		//выбранные свойства товаров в фильтре
		$active=array_keys($properties);

		$options=[];

		//статические фильтры наличия
		$this->customBlock($filter_static,$active,$properties,[
			['value' => 'exist', 'data'=>1, 'title' => Yii ::t('frontend', 'In stock')],
			['value' => 'under', 'data'=>1, 'title' => Yii ::t('frontend', 'Under the order')]
		],'-2');


		$sort=0; //сортировка блоков

        //привязанные фильтры к товарам категории
        foreach ($this->model->filters as $filter) {
            $options = [];
			//виртуальные блоки вильтров
			if($filter->property_id<0){
				if(isset($filter_static[$filter->property_id])){
					$filters[$filter->property_id]=$filter_static[$filter->property_id];
					$filters[$filter->property_id]['sort_order']=$filter->sort_order;
					$filters[$filter->property_id]['status']=1;
				}

				continue;
			}
            if($filter->property->type==Property::TYPE_CHECKBOX){

				$active=array_keys($properties);

				$val = ['value' => $filter->property->id, 'title' => $filter->property->title];
				foreach($custom_group as $f_key=>$prop_ids){//проверяем отношение фильтра к кастомной группе и переносим его туда
					if(in_array($filter->property->id,$prop_ids)){
						//формирование item-го элемента фильтра
						$this->getItemBlock($options, $properties, $filter->property->id, $val, $active,true);
						//перенос итема в кастомную группу
						$filters[$f_key]['options']=array_merge($filters[$f_key]['options'],$options);
						$options=[];
					}
					else{
						//формирование item-го элемента фильтра
						$this->getItemBlock($options, $properties, $filter->property->id, $val, $active);

					}
				}
			}else {
				if (!count($filter->property->propertyStaticValues)) {
					continue;
				}

				$psvl_sql=$filter->property->getPropertyStaticValues()->select('*')
					->leftJoin(PropertyStaticValueLang::tableName(),PropertyStaticValue::tableName().'.id=property_static_value_id')
					->andWhere(['language'=>substr(Yii::$app->language, 0, 2)])
					->orderBy('standart_value DESC')->createCommand()->getRawSql();

$psvl_sql.=",substring(\"property_static_value_lang\".\"title\", '^[0-9]+')::numeric";
$psvl_sql.=",substring(\"property_static_value_lang\".\"title\", '[^0-9]*$')";

				foreach (Yii::$app->db->createCommand($psvl_sql)->queryAll(8) as $propertyStaticValue) {
					//для статических свойств фильтров определяется активный по свойству
					$active = ArrayHelper::getValue($properties, $filter->property_id, []);
					$val = ['value' => $propertyStaticValue->property_static_value_id, 'title' => $propertyStaticValue->title];
					$this->getItemBlock($options, $properties, $filter->property_id, $val, $active);

				}
			}
            if (!count($options)) {
                continue;
            }

            $filters[] = [
                'title' => $filter->property->title,
                'options' => $options,
				'sort_order'=>$filter->sort_order,
            ];
            $sort++;
        }


		ArrayHelper::multisort($filters,'sort_order',SORT_ASC);

        return $this->render('filter', [
            'filters' => $filters,

            'totalCount' => $this->totalCount,
            'decors_type' => $this->getDecorsTypeList($properties),
        	'properties'=>$properties
        ]);
    }

    /**
     * @param $properties
     * @return $this
     */
    public function getQueryList($properties){
        $query = Category::find()
            ->select([Category::tableName() . '.id', 'count("product_category"."product_id")'])
            ->innerJoin(
                ProductCategory::tableName(),
                ProductCategory::tableName() . '.category_id = ' . Category::tableName() . '.id'
            )
            ->innerJoin(
                Product::tableName(),
                ProductCategory::tableName() . '.product_id = ' . Product::tableName() . '.id'
            )
            ->groupBy(Category::tableName() . '.id');
        $query->andWhere([Product::tableName().'.parent_id' => 0]);
        $query->andWhere([Product::tableName().'.is_active' => true]);

        Product::doFiltration($query, $properties);

        return $query;
    }

    /**
     * @param $properties
     * @return array|ActiveRecord[]
     */
    public function getCategoriesList($properties)
    {
        $query = clone $this->getQueryList($properties);
        $query ->addSelect([ CategoryLang::tableName().'.title'])
            ->innerJoin(
            CategoryLang::tableName(),
            Category::tableName() . '.id = ' . CategoryLang::tableName() . '.category_id'
        )->andWhere([CategoryLang::tableName() . '.language' => MultilingualHelper::getLanguageBaseName(Yii::$app->language) ])
        ->addGroupBy(CategoryLang::tableName().'.title');
        $categories = $query->asArray()->all();

        return $categories;
    }

    /**
     * @param $properties
     * @param $id
     * @param $value
     * @return array|int
     */
    public function getPropertyCount($properties, $id, $value)
    {
        unset($properties[$id]); // убрать отфильтрованные варианты текущего параметра
        $properties[$id][] = $value;
        return Product::getFilteredProducts($this->model->id, $properties, true);
    }

    /**
     * @param $properties
     * @return array|bool|mixed
     */
    public function getDecorsList($properties)
    {
        $cache = Yii::$app->cache;

//        $decors_list = ArrayHelper::getValue( $properties, 'decors', [] );
        $decor_type = ArrayHelper::getValue( $properties, 'decor_type', [] );

        $dependency = new DbDependency();
        $dependency->sql = 'SELECT concat(MAX(id), COUNT(id)) FROM decor';
        $key = [
            self::className(),
            Yii::$app->language,
            'DecorsList',
            'category_'.$this->model->id,
            'decors_list_'.md5(\GuzzleHttp\json_encode($properties)),
        ];
        $decors = $cache->get($key);

        if ($decors  === false) {
            $decors = [];
            // $data нет в кэше, вычисляем заново

            // исключение для краек task https://issue.molfar.net/browse/ROST-621
            if ($this->model->id == 724) {
                $cache->set($key, $decors , 3600*24 , $dependency);//3600*24
                return $decors;
            }
            $queryLC = Category::find()->where(['id' => $this->model->id ])->active()->all();
            $ListC = $this->getChildrenCategory($queryLC);

            $query = (new Query())->select(['decor_id as id', 'decor.code as code'
            ])
                ->from(['product'])
                ->leftJoin(
                    'product_category',
                    'product_category.product_id = product.id'
                )
                ->leftJoin('decor', 'decor.id = product.decor_id')
                ->andWhere(
                    [
                        'product.is_active' => true,
                        'decor.is_active' => true,
                        'product_category.category_id' => $ListC,
//                        'product_category.category_id' => $ListC->column(),
                    ]
                )
                ->andWhere([ 'or',
                    [ 'and',
                        ['>', 'product.count' , 0],
                    ],
                    [ 'and',
                        ['=', 'product.count', 0],
                        ['>', 'make_day', 0],
                    ],
                ])
                ->distinct()->groupBy('decor_id, decor.code')->orderBy('decor.code');
            if ($decor_type){
                $query->andWhere(['decor.decor_type_id' => $decor_type]);
            }

            $decors = ArrayHelper::map($query->all(), 'id', 'code');
			$result=[];

			//активный декор
			$active = ArrayHelper::getValue($properties, 'decors', []);
            foreach ($decors as $key => $item){
                $count = $this->getPropertyCount($properties, 'decors' , $key);
				$checked = (in_array($key, $active))? true: false;
				$this->getOptionTag($result,'decors',['value'=>$key,'title'=>$item],$checked,$count);
				if (!$count ) {
                    unset($decors[$key]);
                }
            }

			$decors=$result;
            // Сохраняем значение $data в кэше. Данные можно получить в следующий раз.
            $cache->set($key, $decors, 3600*24 , $dependency);//3600*24
        }
        return $decors;
    }

    /**
     * @param $properties
     * @return array|bool|mixed
     */
    public function getDecorsTypeList($properties)
    {
        $cache = Yii::$app->cache;
        $dependency = new DbDependency();
        $dependency->sql = 'SELECT concat(MAX(id), COUNT(id)) FROM decor_type';
        $key = [
            self::className(),
            Yii::$app->language,
            'TypeDecorsList',
            'category_'.$this->model->id,
        ];
        $decors_type =false;// $cache->get($key);

        if ($decors_type  === false) {
            $decors_type = [];
            // $data нет в кэше, вычисляем заново

            if ($this->model->id == 724) {
                // исключение для краек task https://issue.molfar.net/browse/ROST-621
                $cache->set($key, $decors_type , 3600*24 , $dependency);//3600*24
                return $decors_type;
            }

            $queryLC = Category::find()->where(['id' => $this->model->id ])->active()->all();
            $ListC = $this->getChildrenCategory($queryLC);

            $query = (new Query())->select(['decor_type.id as id', 'decor_type_lang.title as title'
            ])
                ->from(['product'])
                ->leftJoin(
                    'product_category',
                    'product_category.product_id = product.id'
                )
                ->leftJoin('decor', 'decor.id = product.decor_id')
                ->leftJoin('decor_type', 'decor_type.id = decor.decor_type_id')
                ->leftJoin('decor_type_lang', 'decor_type_lang.decor_type_id = decor_type.id')
                ->andWhere(
                    [
                        'product.is_active' => true,
                        'decor.is_active' => true,
                        'decor_type_lang.language' => MultilingualHelper::getLanguageBaseName(Yii::$app->language),
                        'product_category.category_id' => $ListC,
//                        'product_category.category_id' => $ListC->column(),
                    ]
                )
                ->andWhere([ 'or',
                    [ 'and',
                        ['>', 'product.count' , 0],
                    ],
                    [ 'and',
                        ['=', 'product.count', 0],
                        ['>', 'make_day', 0],
                    ],
                ])
                ->distinct()->groupBy('decor_type.id, decor_type_lang.title')->orderBy('decor_type_lang.title');
            $decors_type = ArrayHelper::map($query->all(), 'id', 'title');
			$result=[];
			$active = ArrayHelper::getValue($properties, 'decor_type', []);
			foreach ($decors_type as $key => $item){
				$count = $this->getPropertyCount($properties, 'decor_type' , $key);
				$checked = (in_array($key, $active))? true: false;
				$this->getOptionTag($result,'decor_type',['value'=>$key,'title'=>$item],$checked,$count);
				if (!$count ) {
					unset($decors_type [$key]);
				}
			}
			$decors_type=$result;
            // Сохраняем значение $data в кэше. Данные можно получить в следующий раз.
            $cache->set($key, $decors_type, 3600*24 , $dependency);//3600*24
        }
        return $decors_type;
    }


	/**
	 * кастомный блок фильтров на основе статических данных (наличие...)
	 * @param $filter
	 * @param $static_filters
	 * @param $active
	 * @param array $options_data
	 * @param string $groups
	 *
	 * $filters,$static_filters,$active,$properties,[
	['value' => 'exist', 'title' => Yii ::t('frontend', 'In stock')],
	['value' => 'under', 'title' => Yii ::t('frontend', 'Under the order')]
	],'statuses'
	 */
    private function customBlock(&$filter,$active,$properties,$options_data=[],$groups=''){

    	$result=[];
    	foreach($options_data as $option_item){
			$checked = (in_array($option_item['value'], $active))? true: false;
			if($checked) {
				$count = $this->getPropertyCount($properties, $option_item['value'], $option_item['value']);
			}else{
				$p=$properties;
				foreach($options_data as $exclude){
					if(isset($p[$exclude['value']])){
						unset($p[$exclude['value']]);
					}
				}
				$count = $this->getPropertyCount($p, $option_item['value'], $option_item['value']);
			}
			$this->getOptionTag($result,$option_item['value'],$option_item,$checked,$count);
		}
    	$filter[$groups]['options']=$result;
	}

	/**
	 * формирует item фильтра
	 * @param $result array блок итемов по свойству
	 * @param $properties array выбранные фильтры
	 * @param $property_id int проверяемый property_id
	 * @param $val array значение и id текущего итема
	 * @param array $active array выбранные итемы в свойстве
	 */
    private function getItemBlock(&$result,$properties,$property_id,$val,$active=array(),$group=false){

		$checked = (in_array($val['value'], $active))? true: false;
		$propery_data = Property::findOne($property_id); // для чекбоксов

		$property_val=$val['value'];

		if($propery_data->type==Property::TYPE_CHECKBOX){
			$property_val=(!is_int($val['title'])?1:$val['title']);
			$val['value'] = 1;
			if(!$group) {
				$val['title'] = Yii::t('frontend', 'Yes');
			}else{
			}
		}

		if($checked) {
			$count = (isset($val['count']))?$val['count']:$this->getPropertyCount($properties, $property_id, $property_val);
		}else{
			$p=$properties;
			unset($p[$property_id]);
			$count = (isset($val['count']))?$val['count']:$this->getPropertyCount($p, $property_id, $property_val);
		}
		$this->getOptionTag($result,$property_id,$val,$checked,$count);

	}

	/**
	 * формирование html обертки для итемов
	 * @param $result
	 * @param $property_id
	 * @param $val
	 * @param $checked
	 * @param $count
	 */
	private function getOptionTag(&$result,$property_id,$val,$checked,$count){
		if($count){
			$result[] = Html::tag('div', Html::label(
				Html::checkbox('property[' . $property_id . '][]',
					false,
					[
						'value' => (isset($val['data'])?$val['data']:$val['value']),
						'disabled' => !$count,
						'checked'=>($checked&&$count)
					]
				) . $val['title'] .
				' (' . $count . ')<span class="checkmark"></span>',
				null,
				['class' => 'checkbox-container' . ($count ? '' : ' disabled') .($checked?' checked':'')]
			), ['class' => "filter-item-option checkbox"]
			);
		}
	}

	private function getPopularOptions($popularFilters,$properties){
		$result=[];

		foreach($this->model->popularFilters as $popular){
			if($popular->filter_url) {
				$checked=false;
				//получаем сохраненный урл популярного фильтра и парсим
				$url=parse_url(urldecode($popular->filter_url));
				parse_str($url['query'], $query);
				//сравнение параметров в урл фильтра и гет параметров
				//изврат, т.к. сравниваем многомерный массив
				$arr=array_map("unserialize", array_intersect([0=>serialize($properties)], [0=>serialize($query['property'])]));

				$checbox_url=$popular->filter_url;
				if(count($arr)){
					$checbox_url='';
					$checked=true;
				}
				//подсчет товаров в популярных фильтрах
				$count=Product::getFilteredProducts($this->model->id, $query['property'], true);
				if($count){
					$result[] = Html::tag('div', Html::label(
						Html::checkbox('virtual_property[' . $popular->id . '][]',
							false,
							[
								'value' => urldecode($checbox_url),
								'disabled' => !$count,
								'checked'=>($checked&&$count)
							]
						) . $popular->title .
						' (' . $count . ')<span class="checkmark"></span>',
						null,
						['class' => 'checkbox-container' . ($count ? '' : ' disabled') .($checked?' checked':'')]
					), ['class' => "filter-item-option checkbox"]
					);
				}

			}
		}
			return $result;

	}

    /**
     * @param $tree
     * @return array
     */
    public function getChildrenCategory($tree)
    {
        $list = [];
        foreach ($tree as $item) {
            $list[]= $item->id;
            if (count($item->activeChildren) > 0) {
                $list = array_merge($list, $this->getChildrenCategory($item->activeChildren));
            }
        }
        return $list;

    }
}
