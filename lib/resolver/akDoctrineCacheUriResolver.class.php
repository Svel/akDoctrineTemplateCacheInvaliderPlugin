<?php
/**
 * Doctrine Record related Symfony Template Cache URI resolver.
 *
 * @package     akDoctrineTemplateCacheInvaliderPlugin
 * @subpackage  resolver
 * @author      Nicolas Perriault <np@akei.com>
 */
class akDoctrineCacheUriResolver
{
  protected
    $cacheUri        = null,
    $cacheUriCulture = null,
    $placeholders    = array(),
    $record          = null;

  public function __construct(Doctrine_Record $record, $cacheUri)
  {
    $this->record = $record;
    $this->cacheUri = $cacheUri;
    $this->cacheUriCulture = $this->checkCulture();
    $this->placeholders = $this->checkPlaceholders();
  }

  public function checkCulture()
  {
    if (preg_match('/sf_culture=([a-z_]{2,5})/i', $this->cacheUri, $m))
    {
      return $m[1];
    }

    return null;
  }

  public function checkPlaceholders()
  {
    if (preg_match_all('/%([a-z0-9\._]+)%/si', $this->cacheUri, $m) && count($m[1]))
    {
      return $m[1];
    }

    return array();
  }

  public function hasTranslation(Doctrine_Record $record, $culture)
  {
    return $record->hasRelation('Translation') && array_key_exists($culture, $record->Translation->toArray());
  }

  public function getRelatedFields()
  {
    if (!count($this->placeholders))
    {
      return array();
    }

    $relatedFields = array();

    foreach ($this->placeholders as $field)
    {
      $element = $field;

      $currentRecord = $this->record;

      if (strpos($field, '.'))
      {
        foreach (explode('.', $field) as $element)
        {
          if (!$currentRecord->getTable()->hasRelation($element))
          {
            break;
          }

          $relationType = $currentRecord->getTable()->getRelation($element)->getType();

          if (Doctrine_Relation::ONE === $relationType)
          {
            $currentRecord = $currentRecord->$element;
          }
          else if (Doctrine_Relation::MANY === $relationType)
          {
            // TODO: handle Doctrine_Collection to invalidate all related collections uris?
            break;
          }
          else
          {
            break;
          }
        }
      }

      $relatedFields[sprintf('%%%s%%', $field)] = $this->fetchRelatedValues($currentRecord, $element);
    }

    return $relatedFields;
  }

  public function fetchRelatedValues(Doctrine_Record $record, $property)
  {
    if ($record->getTable()->isIdentifier($property))
    {
      $value = $record->$property;

      return $value ? array($value) : array();
    }

    $values = array();
    $skipDirectPropertyGet = false;

    // Record available translations of property, if available
    if ($record->hasRelation('Translation'))
    {
      if (!is_null($this->cacheUriCulture) && $this->hasTranslation($record, $this->cacheUriCulture))
      {
        $translations = array($record->Translation[$this->cacheUriCulture]);

        $skipDirectPropertyGet = true;
      }
      else
      {
        $translations = $record->Translation;
      }

      foreach ($translations as $translation)
      {
        if (isset($translation[$property]) && $translation[$property])
        {
          $values[] = $translation[$property];
        }
      }
    }

    // Standard property get
    if (false === $skipDirectPropertyGet)
    {
      try
      {
        // circumvents a silly Doctrine behavior which may alter the record instance reference
        // when trying to access some of its properties, so we copy it instead to be safe
        $copy = $record->copy(false);
        if ($value = (string) $copy->$property)
        {
          $values[] = $value;
        }
        $copy->free(true);
      }
      catch (Exception $e)
      {
        $values[] = '*';
      }
    }

    return array_unique($values);
  }

  /**
   * Computes cache URIs
   *
   * @return array
   */
  public function computeUris()
  {
    $computedCacheUris = array();

    if (!count($this->placeholders))
    {
      $computedCacheUris[] = $this->cacheUri;
      return $computedCacheUris;
    }

    // generate the combinations
    $patterns = array();

    foreach ($this->getRelatedFields() as $pattern => $values)
    {
      $patterns[] = $pattern;
      $combinations = isset($combinations) ? $this->expandCombinations($combinations, $values) : $values;
    }

    // generate the uris for each combination
    foreach ($combinations as $combination)
    {
      $computedCacheUris[] = str_replace($patterns, $combination, $this->cacheUri);
    }

    return array_unique($computedCacheUris);
  }

  /**
   * Adds a level of depth in the combinations for each new array of values
   *
   * @author lheurt (http://stackoverflow.com/users/506682/lheurt)
   * @link   http://stackoverflow.com/questions/4172020/recursive-strings-replacement-with-php/4172467
   */
  protected function expandCombinations($combinations, $values)
  {
    $results = array();
    $i = 0;
    //combine each existing combination with all the new values
    foreach($combinations as $combination)
    {
      foreach($values as $value)
      {
        $results[$i] = is_array($combination) ? $combination : array($combination);
        $results[$i][] = $value;
        $i++;
      }
    }
    return $results;
  }
}
