<?php

namespace frontend\controllers;

use Codeception\Module\Filesystem;
use common\components\serviceGib;
use common\models\CategoryTemplate;
use common\models\Contractor;
use common\models\Customer;
use common\models\Document;
use common\models\NonStandardNote;
use common\models\Order;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use trntv\filekit\Storage;
use yii;
use ZipArchive;
use yii\base\BaseObject;
use yii\helpers\FileHelper;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\ForbiddenHttpException;
use common\models\OrderItemNonStandard;
use common\models\Product;
use common\models\Decor;
//use linslin\yii2\curl;
use yii\widgets\ActiveForm;
use common\helpers\MultilingualHelper;
use common\models\CategoryLang;
use common\models\DecorTypeLang;
use common\models\ProductCategory;
use common\models\ProductProperty;
use yii\base\Widget;
use yii\db\Query;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\caching\DbDependency;
use common\components\MyDebug;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use common\components\communication1C;
use common\components\interaction1C;
use trntv\filekit\actions\UploadAction;
use trntv\filekit\actions\DeleteAction;
use common\commands\SendEmailCommand;
use yii\helpers\Json;


class NonStandardController extends Controller
{

    public $model = null;
    public $totalCount = 0;

    public function actions()
    {
        return [
            'upload-exel' => [
                'class' => UploadAction::class,
                'deleteRoute' => 'delete-exel',
            ],
            'delete-exel' => [
                'class' => DeleteAction::class
            ],
        ];
    }

    /**
     * @param null $orderId
     * @return string
     * @throws ForbiddenHttpException
     */
    public function actionIndex($orderId = null)
    {
		// получить покупателя и order_id

        if($orderId){
            $customer = Customer::findOne(['user_id' => Yii::$app->user->id]);
            if ( null === $customer) {
                throw new ForbiddenHttpException('Ви не є покупцем', 403);
            }
            $order = Order::find()->where(['id' => $orderId, 'order_status_id' => Order::STATUS_SAVED, 'customer_id' => $customer->id])->one();
            if ( null === $order) {
                throw new ForbiddenHttpException('Вам не дозволено дивитись чужі замовлення або замовлення не існує', 403);
            }
        }else if (is_null($order = Order::getOrder())){
            $order = Order::getOrder(true);
        }

		$model = new OrderItemNonStandard();
		$model->order_id = $order->id;
		$model->additive_side = '0000';
		$model->handle_side = '0000';
		$model->edge_type = '1111';

		$order_note_model=new NonStandardNote();
		$order_note_model->load(['NonStandardNote'=>['order_note'=>$order->comment]]);
		$order_note_model->load(['NonStandardNote'=>['id'=>$order->id]]);

		$document = Document::findOne(['id'=> 15]);  // жесткая привязка к бланку !
        $document_url = $document->getDocumentAttachments()->limit(1)->one();
//        MyDebug::debug($document_url);
//        die();
//        $document_url ='#';
//        if ($url){
//            $document_url = $url->getUrl();
//        }

		return $this->render('index', [
			'model' => $model,
			'order_note_model'=>$order_note_model,
            'items'=> $order->itemsNonStandard,
            'order' => $order,
            'document_url' => $document_url ,
		]);
    }

    /**
     * @param $id
     * @return string
     */
    public function actionEditGib($id)
    {
        if (!$id) return '';

        $model = OrderItemNonStandard::findOne(['id'=> $id]);

        return $this->render('gibxnc', [
            'model' => $model,
            'xnctemplate' => 1,
        ]);
    }

    /**
     * @return array|string
     */
    public function actionListDecor()
    {
        if (Yii::$app->request->isAjax) {

            Yii::$app->response->format = Response::FORMAT_JSON;

            $result = [];

            $id = Yii::$app->request->post('id');
            if (empty($id )) return 'not have parametr id';

            $products =Product::find()
                ->active()
                ->andWhere(['main_category_id'=> $id]);

//            $result['sql'] = MyDebug::debugsql($products);

            $result['data'] ='';

            foreach ($products->each(10) as $product){
                $result['data'] .= Html::tag('div',
                    Html::img(
                        Yii::$app->glide->createSignedUrl([
                            'glide/index',
                            'path' => $product->decor->path,
                            'w' => 230,
                            'h' => 138,
                            'fit' => 'fill',
                        ], true)
                    ) .
                    Html::tag('p', $product->decor->title . '<br />'. $product->decor->code  )
                    ,['class'=>'decor-item', 'data-product' => $product->short_title, 'data-product_id' => $product->id]);
            }

            return $result ;
        }
        return 'error' ;
    }

    /**
     * @return array|string
     */
    public function actionProducts()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        if (!Yii::$app->request->isAjax) return 'not Ajax';

        $request = Yii::$app->request;
        $id =  $request->post("id");
        if (!$id)  return 'not have id';

        $result = [];
        $products = Product::find()->localized()->select('id')
            ->where(['main_category_id' => $id])
            ->active()
            ->all();
        foreach ($products as $product){
            $result[] = ['id' => $product->id, 'title' =>  $product->short_title];
        }
        ArrayHelper::multisort($result , 'title');
//        $result = ArrayHelper::map($products, 'id', 'short_title');

        return $result;
    }


    /**
     * @return string|json
     */
    public function actionDekorprice()
    {
		if (Yii::$app->request->isAjax) { 
			$data = Yii::$app->request->post();		

			$product_id = $data['product_id'];
			$product = Product::find()->localized()->where(['id' => $product_id])->one();

			if ($product){

                $price = [
                    'price'=>number_format(round($product->price, 2), 2, '.', ''),
                    'discont' =>  0
                ];
                if(!Yii::$app->user->isGuest ) {
                    //check discount_perc on API 1C
                    $result1C = $this->getDiscount1C($product);

                    if ($result1C ){
                        $price = [
                            'price' =>number_format(round($result1C ->Data[0]->price, 2), 2, '.', ''),
                            'discont' =>  $result1C ->Data[0]->discount_perc
                        ];
                    }
                }

				return json_encode($price);
			}
        }
        return json_encode(false);
    }

	/**
	 * добавление фасада вручную
	 * @return string
	 */
	public function actionAddtoorderstable()
    {
		$model = new OrderItemNonStandard();

		$data = Yii::$app->request->post();
		if(! \Yii::$app->request->isAjax) return 'not ajax';

        if ($model->load($data) && $model->validate() ) {
			echo $this->addToorderstable($model);
        } else return \GuzzleHttp\json_encode($model->errors);
    }

    /*
     * Общий метод добавления фасада в список вручную + excel
     */
    private function addToorderstable(OrderItemNonStandard $model){

		$product = Product::findOne(['id'=> $model->product_id]);

		$model->price_per_pcs = $product->price;
		$side = str_split($model->edge_type);
		if($side){
			$model->side_top = $side[0];
			$model->side_right = $side[1];
			$model->side_bottom = $side[2];
			$model->side_left = $side[3];
		}
		if ($model->isNewRecord) {
			$decor = Decor::find()->where(['id' => $product->decor_id])->one();
			$model->take_structure = $decor->use_structure;
		}
		$model->save();

		$square = $model->square;
		$perimeter = $model->perimeter;
//MyDebug::debug($model->total);
		//цена

		$item_price = number_format(round($model->total, 2), 2, '.', '');

        $color = '';
        if (strlen($model->data_project) > 1200)
            $color = 'xnc';

        $ret = '<tr data-order-item-nonstandard-id="'. $model->id .'">
                <td class="headcol"></td>
                <td>'.$product->decor->code.'</td>
                <td>'.$model->lenght.'</td>
                <td>'.$model->width.'</td>
                <td class="quantity">'.$model->quantity.'</td>
                <td class="splice ';
        if ((int)$model->splice){
            $ret .=  "on";
        }
        $ret .= '" style="display: none;"></td>
                <td><div class="curent radio edge_type_'. $model->edge_type .'"><label></label></td> 
                <td>'. $model->getEdge()[$model->decor_code] .'</td>
                <td>'. $model->handleTitle .'</td>
                <td><div class="curent radio handle_side_'. $model->handle_side .'"><label></label></div></td>
                <td><div class="curent radio additive_side_'. $model->additive_side .'"><label></label></div></td>
                <td class="square">'. $square .'</td>
                <td class="item-price">'. $item_price .'</td>
                <td>'. $model->comment .'</td>
                <td class="actions">'
            . Html::a('',['non-standard/edit-gib', 'id'=>$model->id],['class'=>"btn upd-btn ".$color, 'rel'=>"nofollow"])
            . '</td>
                <td class="actions">';
        if (!empty($color))
            $ret .= Html::a('',['non-standard/view-pdf', 'id'=>$model->id],['class'=>"btn pdf-btn",  'target'=>"_blank", 'rel'=>"nofollow"]);
        $ret .= '<button type="button" class="btn del-btn" data-action="removeFromCartNonSt" data-id="'. $model->id .'">X</button>
                </td>';

		return $ret;
	}

    /**
     * @param null $id
     * @return array
     */
    public function actionValidate($id = null)
	{
	   $model = new OrderItemNonStandard();
		$model->load(Yii::$app->request->post());
		 Yii::$app->response->format = Response::FORMAT_JSON;
	   return ActiveForm::validate($model);
	}

	/**
	 * валидация коммента и добавление его к заказу
	 * @param null $id
	 * @return array
	 */
	public function actionValidatenote($id = null)
	{
		$model = new NonStandardNote();
		$model->load(Yii::$app->request->post());
		Yii::$app->response->format = Response::FORMAT_JSON;
		$isvalid=ActiveForm::validate($model);
		if(empty($isvalid)){
			$model->setOrderComment();
		}
		return $isvalid;
	}

    /**
     * Дубль функционала CartController
     * @param @property $product common\models\Product
     * @return array|\common\components\stdClass
     */
    protected function getDiscount1C($product)
    {
        if(Yii::$app->user->isGuest ) return [];
        $login1C = ArrayHelper ::getValue(Yii ::$app -> params, 'interaction1C.login','');
        $user = Yii::$app->user->getIdentity();
        $contractor = $user->userProfile->contractor;
        if( $contractor && $product && !empty($login1C) ) {
//            $api1C = new communication1C($login1C , ArrayHelper ::getValue(Yii ::$app -> params, 'interaction1C.pasword',''), ArrayHelper ::getValue(Yii ::$app -> params, 'interaction1C.url', ''));

            $api1C = new communication1C($login1C , ArrayHelper ::getValue(Yii ::$app -> params, 'interaction1C.pasword',''), 'https://ws.rost.ua/rost/hs/SiteExchangeApi/');
            $data = [
                'contractor_code'=>$contractor->code,
                'products'=> [
                    [
                        'product_code'=>$product->code,
                        'unit_id'=>$product->unit->code,
                    ],
                ],
            ];

            $result = $api1C -> api('GetPriceAndStockBalance', $data);
            if ($api1C -> get_response_code() != 200) {
//                Yii ::error('error transfer data to API getDiscount1C ' . var_export($result, true), 'api1C');
                $result = [];
            }

            return $result;
        }
        return [];
    }

    /**
     * @return array|string
     */
    public function actionSendExel()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $result = [];
        if (!Yii::$app->request->isAjax) return $result['error'] = 'not Ajax';

        $request = Yii::$app->request;
        $result['success'] = 'ok';
        $file = $request ->post('exel','');

        //https://phpspreadsheet.readthedocs.io/en/latest/
		if(!isset($file['path'])){
			$result['errors']['string']=Yii::t('frontend','Upload the file');
			return $result;
		}
		$excel=IOFactory::load(Yii::getAlias('@storage/web/source/'.$file['path']));
		$sheet = $excel->getActiveSheet(0);
		$sku=preg_replace('/[^a-zA-Zа-яА-Я0-9]/ui','',$sheet->getCellByColumnAndRow(28, 18)->getCalculatedValue()); //обрезка непечатных символов
		if (is_null($model_product=Product::find()->andWhere("code LIKE '".$sku."%'")->one())){
			$result['errors']=Yii::t('frontend','Import file is corrupted in cell [28,18]');;
			return $result;
		}

		$valid_items=[];//массив экземпляров OrderItemNonStandard прошедших валидацию

		//проходим по списку
		for ($i=33;$i<=144;$i++){
				//2-h,3-w, 4-q
			if($sheet->getCellByColumnAndRow(2,$i)->getCalculatedValue()!=''&&$sheet->getCellByColumnAndRow(3,$i)->getCalculatedValue()!='') {
				//кромка в цвет/кастом
				$dc=$sheet->getCellByColumnAndRow(5, 21)->getCalculatedValue();
				//ручки
				$handle=$sheet->getCellByColumnAndRow(28, $i)->getCalculatedValue();

				$model = new OrderItemNonStandard();

				if($handle) {
					$handle = preg_replace('/[^a-zA-Zа-яА-Я0-9]/ui', '', $handle);
					if (is_null($model_handle = Product::find()->andWhere("code LIKE '" . $handle . "%'")->one())) {
						$handle = 0;
					} else {
						$handle = $model_handle->id;
						$model->price_handle=$model_handle->price;
					}
				}

				if($sheet->getCellByColumnAndRow(7, $i)->getCalculatedValue()===null
					||$sheet->getCellByColumnAndRow(5, $i)->getCalculatedValue()===null
					||$sheet->getCellByColumnAndRow(9, $i)->getCalculatedValue()===null){
					$model->addError('empty', Yii::t('frontend','Error! Cell is empty in string {str}',['str'=>$i]));
					$result['errors']['model']=$model->getErrors();
					$result['errors']['string']=Yii::t('frontend','Error in string {str}',['str'=>$i]);
					return $result;
				}

				$model->product_id=$model_product->id;
				$model->price_per_pcs=$model_product->price;
				$model->decor_code=(($dc)?'1':'2');
				$model->lenght = $sheet->getCellByColumnAndRow(2, $i)->getCalculatedValue();
				$model->width = $sheet->getCellByColumnAndRow(3, $i)->getCalculatedValue();
				$model->quantity = $sheet->getCellByColumnAndRow(4, $i)->getCalculatedValue();
				$model->edge_type = OrderItemNonStandard::getEdgeTypeExFormat($sheet->getCellByColumnAndRow(5, $i)->getCalculatedValue());
				$model->handle = $handle;
				$model->handle_side = OrderItemNonStandard::getHandleTypeExFarmat($sheet->getCellByColumnAndRow(7, $i)->getCalculatedValue());
				$model->additive_count = $sheet->getCellByColumnAndRow(9, $i)->getCalculatedValue() == 0 ? 0 : 1;
				$model->additive_side = OrderItemNonStandard::getAdditiveTypeExFarmat($sheet->getCellByColumnAndRow(9, $i)->getCalculatedValue());
				$model->comment = $sheet->getCellByColumnAndRow(13, $i)->getCalculatedValue();

				//если нет номера заказа- создаем
				if (is_null($order = Order::getOrder())){
					$order = Order::getOrder(true);
				}
				$model->order_id=$order->id;
				//валидация и вывод ошибок
				if($model->validate()){
					$valid_items[]=$model;
				}else{
					$result['errors']['model']=$model->getErrors();
					$result['errors']['string']=Yii::t('frontend','Error in string {str}',['str'=>$i]);
					return $result;
				};
			}
		}
		//передаем на сохранение
		if(!empty($valid_items)){
			foreach ($valid_items as $model){
				$result['items'][]=$this->addToorderstable($model);
			}
		}else{
			$result['errors']['string']=Yii::t('frontend','File is empty');
		}

        return $result;
    }

    public function actionUpdateProject()
    {
        if (!Yii::$app->request->isAjax) return Json::encode(['message' => 'not Ajax']);

        Yii ::$app -> response -> format = Response::FORMAT_JSON;
        $request = Yii ::$app -> request;
        $id = $request ->post('id');

        $item = OrderItemNonStandard::findOne(['id'=> $id]);
        if ($item){
            $project = $request ->post('project');
            $item->data_project = str_replace("'", "\'", $project) ;
            $item->save();

            serviceGib::UploadGibArchive($item, true);

            return Json::encode([
                'message' => 'Project saved',
//                'data' => $item->attributes
            ]);
        } else
            return Json::encode([ 'message' => 'not found this project']);

    }

    /**
     * @param $id
     * @return $this
     * @throws NotFoundHttpException
     */
    public function actionViewPdf($id)
    {
        $item = OrderItemNonStandard::findOne(['id'=> $id]);
        $name = '/giblab/order'.$item ->order_id.'/'.$item ->id.'.zip';
        $namepdf = '/giblab/Project '.$item ->order_id.'_'.$item ->id.'.pdf';
        $link = Yii::getAlias('@storage/web/source'.$name);
        $url = Yii::getAlias('@storageUrl/source'.$name);
        $urlpdf = Yii::getAlias('@frontendUrl/storage/cache/'.$namepdf);

        if (!Yii::$app->fileStorage->getFilesystem()->has($name)){
            serviceGib::UploadGibArchive($item);
        }
        $zip = new ZipArchive;
        $extract = $zip ->open($link);
        if ($extract === TRUE) {
            $result = Yii::$app->fileStorage->getFilesystem()->createDir(Yii::getAlias('@storage/cache/giblab'));
            $zip->extractTo(Yii::getAlias('@storage/cache/giblab'));
            $zip->close();
        } else {
            Yii::error('error extract zip '.$link. \GuzzleHttp\json_encode($extract ),'zip');
            Yii::$app->session->setFlash('alert', [
                'body' => Yii::t('frontend', 'Проблемы с архивом чертежа'),
                'options' => ['class' => 'alert alert-success'],
            ]);
            throw new NotFoundHttpException;
        }
        return Yii::$app->response->redirect($urlpdf."?v=".filemtime(Yii::getAlias('@storage/cache/'.$namepdf)));
    }

    /**
     * @return array
     * создание письма менеджеру на проверку заказа
     */
    public function actionCheck()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $request = Yii::$app->request;
        $orderId = $request->post('orderId', null);
        $result = [];

        if(isset($orderId)){
            $order = Order::find()->where([
                'id' => $orderId,
                'order_status_id'=>
                    [Order::STATUS_SAVED, Order::STATUS_CART]
            ])->one();
            if (null === $order) {
                $result['message'] = 'не знайдено заказ';
            }
        } else {
            $result['message'] = 'немає данних';
        }
        // создать письмо менеджеру

        $manager_mail = Yii::$app->keyStorage->get('email.roznica', \Yii::$app->params['adminEmail']);

        if ($order->contractor_id){
            $сontractor = Contractor::findOne($order->contractor_id);
            $client = $сontractor->name;
            $manager_mail = $сontractor->manager->email;
        } else if ($order->customer_id > 0) {
            $client =  $order->customer->phone.' '.$order->customer->first_name.' '.$order->customer->last_name;
        } else {
            $client = ' РОЗДРІБ ';
        }

        Yii::$app->commandBus->handle(new SendEmailCommand([
            'subject' => Yii::t('frontend', 'Фасад замовлення на перевірку {order} із сайту Rost.ua - {client} ',[
                    'order'=> ' ('.$order->id.')',
                    'client' => $client,
                ]) ,
            'view' => 'check_furniture_front',
            'to' => $manager_mail,
            'from' => [\Yii::$app->params['robotEmail'] => Yii::t('frontend','Rost LLC')],
            'params' => [
                'order' => $order,
                'client' => $client
            ],
        ]));

        $result['message'] = 'листа менеджеру відправлено ';

        return $result;
    }

}
