<?php
/**
 * @copyright Copyright (c) 2013 2amigOS! Consulting Group LLC
 * @link http://2amigos.us
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 */

namespace dosamigos\translateable;

use Yii;
use yii\base\Behavior;
use yii\base\Event;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;

/**
 * TranslateBehavior Behavior. Allows to maintain translations of model.
 *
 * @author Antonio Ramirez <amigo.cobos@gmail.com>
 * @link http://www.ramirezcobos.com/
 * @link http://www.2amigos.us/
 * @package dosamigos\translate
 */
class TranslateableBehavior extends Behavior
{
    /**
     * @var string the name of the translations relation
     */
    public $relation = 'translations';

    /**
     * @var string the language field used in the related table. Determines the language to query | save.
     */
    public $languageField = 'language';

    /**
     * @var array the list of attributes to translate. You can add validation rules on the owner.
     */
    public $translationAttributes = [];

    /**
     * @var null|boolean is `true` if the loaded translation is loaded from the fallback language
     */
    public $isFallback = null;

    /**
     * @var ActiveRecord[] the models holding the translations.
     */
    private $_models = [];

    /**
     * @var string the language selected.
     */
    private $_language;


    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete'
        ];
    }

    /**
     * Make [[$translationAttributes]] writable
     */
    public function __set($name, $value)
    {
        if (in_array($name, $this->translationAttributes)) {
            if (is_array($value) and isset($value['translations'])) {
                foreach ($value['translations'] as $language => $translatedValue) {
                    $this->getTranslation($language)->$name = $translatedValue;
                }
            } else {
                $this->getTranslation()->$name = $value;
            }
        } else {
            parent::__set($name, $value);
        }
    }

    /**
     * Make [[$translationAttributes]] readable
     * @inheritdoc
     */
    public function __get($name)
    {
        if (!in_array($name, $this->translationAttributes) && !isset($this->_models[$name])) {
            return parent::__get($name);
        }

        if (isset($this->_models[$name])) {
            return $this->_models[$name];
        }

        $model = $this->getTranslation();
        return $model->$name;
    }

    /**
     * Expose [[$translationAttributes]] writable
     * @inheritdoc
     */
    public function canSetProperty($name, $checkVars = true)
    {
        return in_array($name, $this->translationAttributes) ? true : parent::canSetProperty($name, $checkVars);
    }

    /**
     * Expose [[$translationAttributes]] readable
     * @inheritdoc
     */
    public function canGetProperty($name, $checkVars = true)
    {
        return in_array($name, $this->translationAttributes) ? true : parent::canGetProperty($name, $checkVars);
    }

    /**
     * @param \yii\base\Event $event
     */
    public function afterFind($event)
    {
        $this->populateTranslations();
        $this->getTranslation($this->getLanguage());
    }

    /**
     * @param \yii\base\Event $event
     */
    public function afterInsert($event)
    {
        $this->saveTranslation();
    }

    /**
     * @param \yii\base\Event $event
     */
    public function afterUpdate($event)
    {
        $this->saveTranslation();
    }

    /**
     * @param \yii\base\Event $event
     */
    public function afterDelete($event)
    {
        foreach ($this->owner->translations as $translation) {
            $translation->delete();
        }
    }

    /**
     * Sets current model's language
     *
     * @param $value
     */
    public function setLanguage($value)
    {
        $value = strtolower($value);
        if (!isset($this->_models[$value])) {
            $this->_models[$value] = $this->loadTranslation($value);
        }
        $this->_language = $value;
    }

    /**
     * Returns current models' language. If null, will return app's configured language.
     * @return string
     */
    public function getLanguage()
    {
        if ($this->_language === null) {
            $this->_language = Yii::$app->language;
        }
        return $this->_language;
    }

    /**
     * Saves current translation model
     * @return bool
     */
    public function saveTranslation()
    {
        $ret = true;

        foreach ($this->_models as $model) {
            $dirty = $model->getDirtyAttributes();
            if (empty($dirty)) {
                continue; // we do not need to save anything
            }
            /** @var \yii\db\ActiveQuery $relation */
            $relation = $this->owner->getRelation($this->relation);
            $pks = $relation->link;

            foreach ($pks as $fk => $pk) {
                $model->$fk = $this->owner->$pk;
            }

            if (!$model->save()) {
                $ret = false;
            }
        }

        return $ret;
    }

    /**
     * Returns a related translation model
     *
     * @param string|null $language the language to return. If null, current sys language
     *
     * @return ActiveRecord
     */
    public function getTranslation($language = null)
    {
        if ($language === null) {
            $language = $this->getLanguage();
        }

        if (!isset($this->_models[$language])) {
            $this->_models[$language] = $this->loadTranslation($language);
        }

        return $this->_models[$language];
    }

    /**
     * Loads all specified languages. For example:
     *
     * ```
     * $model->loadTranslations("en-US");
     *
     * $model->loadTranslations(["en-US", "es-ES"]);
     *
     * ```
     *
     * @param string|array $languages
     */
    public function loadTranslations($languages)
    {
        $languages = (array)$languages;

        foreach ($languages as $language) {
            $this->loadTranslation($language);
        }
    }

    /**
     * Loads a specific translation model
     *
     * @param string $language the language to return
     *
     * @return null|\yii\db\ActiveQuery|static
     */
    private function loadTranslation($language, $isFallback = false)
    {
        $translation = null;
        /** @var \yii\db\ActiveQuery $relation */
        $relation = $this->owner->getRelation($this->relation);
        /** @var ActiveRecord $class */
        $class = $relation->modelClass;
        $oldAttributes = $this->owner->getOldAttributes();
        $searchFields = [$this->languageField => $language];

        foreach ($relation->link as $languageModelField => $mainModelField) {
            if (empty($oldAttributes)) {
                $searchFields[$languageModelField] = $this->owner->$mainModelField;
            } else {
                $searchFields[$languageModelField] = $oldAttributes[$mainModelField];
            }
        }

        $translation = $class::findOne($searchFields);

        // TODO: this is just a hack for creating records
        if (Yii::$app->request->isPost) {
            $isFallback = true;
        }

        if ($translation === null) {
            $fallbackLanguage = Yii::$app->params['fallbackLanguage'][Yii::$app->language] ?? null;
            if ($fallbackLanguage && !$isFallback) {
                return $this->loadTranslation(Yii::$app->params['fallbackLanguage'][Yii::$app->language], true);
            } else {
                $translation = new $class;
                $translation->setAttributes($searchFields);
            }
        }

        $this->isFallback = $isFallback;

        return $translation;
    }

    /**
     * Populates already loaded translations
     */
    private function populateTranslations()
    {
        //translations
        $aRelated = $this->owner->getRelatedRecords();
        if (isset($aRelated[$this->relation]) && $aRelated[$this->relation] != null) {
            if (is_array($aRelated[$this->relation])) {
                foreach ($aRelated[$this->relation] as $model) {
                    $this->_models[$model->getAttribute($this->languageField)] = $model;
                }
            } else {
                $model = $aRelated[$this->relation];
                $this->_models[$model->getAttribute($this->languageField)] = $model;
            }
        }
    }
} 
