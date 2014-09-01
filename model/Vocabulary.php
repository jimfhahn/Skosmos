<?php
/**
 * Copyright (c) 2012-2013 Aalto University and University of Helsinki
 * MIT License
 * see LICENSE.txt for more information
 */

/**
 * Vocabulary dataobjects provide access to the vocabularies on the SPARQL endpoint.
 */
class Vocabulary extends DataObject
{
  /** the preferred order of the vocabulary information properties */
  public $order;

  /** cached value of URI space */
  private $urispace = null;

  /**
   * Extracts the vocabulary id string from the baseuri of the vocabulary.
   * @return string identifier eg. 'mesh'.
   */
  public function getId()
  {
    $uriparts = explode("#", $this->resource->getURI());
    if (count($uriparts) != 1)
    // hash namespace
      return $uriparts[1];
    // slash namespace
    $uriparts = explode("/", $voc->getURI());

    return $uriparts[count($uriparts) - 1];
  }

  /**
   * Returns the human readable vocabulary title.
   * @return string the title of the vocabulary
   */
  public function getTitle()
  {
    $literal = $this->resource->getLiteral('dc:title', $this->lang);
    if ($literal)
      return $literal->getValue();
    // not found with selected language, try any language
    return $this->resource->getLiteral('dc:title')->getValue();
  }

  /**
   * Get the languages supported by this vocabulary
   * @return array languages supported by this vocabulary (as language tag strings)
   */
  public function getLanguages()
  {
    $langs = $this->resource->allLiterals('skosmos:language');
    $ret = array();
    foreach ($langs as $lang) {
      $ret[] = $lang->getValue();
    }

    return $ret;
  }

  /**
   * Get the default language of this vocabulary
   * @return string default language, e.g. 'en'
   */

  public function getDefaultLanguage()
  {
    $deflang = $this->resource->getLiteral('skosmos:defaultLanguage');
    if ($deflang) return $deflang->getValue();
    $langs = $this->getLanguages();
    if (sizeof($langs) > 1)
      trigger_error("Default language for vocabulary '" . $this->getId() . "' unknown, choosing '$langs[0]'.", E_USER_WARNING);

    return $langs[0];
  }

  /**
   * Get the SPARQL endpoint URL for this vocabulary
   *
   * @return string endpoint URL
   */
  public function getEndpoint()
  {
    return $this->resource->get('void:sparqlEndpoint')->getUri();
  }

  /**
   * Get the SPARQL graph URI for this vocabulary
   *
   * @return string graph URI
   */
  public function getGraph()
  {
    $graph = $this->resource->get('skosmos:sparqlGraph');
    if ($graph)
      $graph = $graph->getUri();

    return $graph;
  }

  /**
   * Get the SPARQL implementation for this vocabulary
   *
   * @return Sparql SPARQL object
   */
  public function getSparql()
  {
    $endpoint = $this->getEndpoint();
    $graph = $this->getGraph();
    $dialect = $this->resource->get('skosmos:sparqlDialect');
    $dialect = $dialect ? $dialect->getValue() : DEFAULT_SPARQL_DIALECT;

    return $this->model->getSparqlImplementation($dialect, $endpoint, $graph);
  }

  /**
   * Get the URI space of concepts in this vocabulary.
   *
   * @return string full URI of concept
   */
  public function getUriSpace()
  {
    if ($this->urispace == null) // initialize cache
      $this->urispace = $this->resource->getLiteral('void:uriSpace')->getValue();

    return $this->urispace;
  }

  /**
   * Get the full URI of a concept in a vocabulary. If the passed local
   * name is already a full URI, return it unchanged.
   *
   * @param $lname string local name of concept
   * @return string full URI of concept
   */
  public function getConceptURI($lname)
  {
    if (strpos($lname, 'http') === 0) return $lname; // already a full URI
    return $this->getUriSpace() . $lname;
  }

  /**
   * Asks the sparql implementation to make a label query for a uri.
   * @param string $uri
   * @param string $lang
   */
  public function getConceptLabel($uri, $lang)
  {
    return $this->getSparql()->queryLabel($uri,$lang);
  }

  /**
   * Get the localname of a concept in the vocabulary. If the URI is not
   * in the URI space of this vocabulary, return the full URI.
   *
   * @param $uri string full URI of concept
   * @return string local name of concept, or original full URI if the local name cannot be determined
   */
  public function getLocalName($uri)
  {
    return str_replace($this->getUriSpace(), "", $uri);
  }

  /**
   * Wether the alphabetical index is small enough to be shown all at once.
   * @return boolean true if all concepts can be shown at once.
   */
  public function getAlphabeticalFull()
  {
    $val = $this->resource->getLiteral('skosmos:fullAlphabeticalIndex');
    if ($val)
      return (boolean) $val->getValue();
    return false;
  }
  
  /**
   * Returns a short name for a vocabulary if configured. If that has not been set 
   * using vocabId as a fallback.
   * @return string
   */
  public function getShortName()
  {
    $val = $this->resource->getLiteral('skosmos:shortName');
    if ($val)
      return $val->getValue();
    return $this->getId();
  }

  /**
   * Retrieves all the information about the Vocabulary
   * from the SPARQL-endpoint.
   */
  public function getInfo()
  {
    $ret = array();

    // get metadata from vocabulary configuration file
    foreach ($this->resource->properties() as $prop) {
      foreach ($this->resource->allLiterals($prop, $this->lang) as $val) {
        $ret[$prop][] = $val->getValue();
      }
      foreach ($this->resource->allResources($prop) as $val) {
        $label = $val->label($this->lang);
        if ($label) {
          $ret[$prop][] = $label->getValue();
        }
      }
    }

    // also include ConceptScheme metadata from SPARQL endpoint
    $cs = $this->getDefaultConceptScheme();

    // query everything the endpoint knows about the ConceptScheme
    $sparql = $this->getSparql();
    $result = $sparql->queryConceptScheme($cs);
    $cs = $result->resource($cs);
    $this->order = array("dc:title","dc11:title","skos:prefLabel","rdfs:label","dc:subject", "dc11:subject", "dc:description", "dc11:description","dc:publisher","dc11:publisher","dc:creator","dc11:creator","dc:contributor", "dc:language", "dc11:language","owl:versionInfo","dc:source", "dc11:source");

    foreach ($cs->properties() as $prop) {
      foreach ($cs->allLiterals($prop, $this->lang) as $val) {
        $ret[$prop][] = $val->getValue();
      }
      if (!isset($ret[$prop]) || sizeof($ret[$prop]) == 0) { // not found with language tag
        foreach ($cs->allLiterals($prop, null) as $val) {
          $v = $val->getValue();
          if ($v instanceof DateTime) {
            $v = $v->format('Y-m-d H:i:s');
          }
          $ret[$prop][] = $v;
        }
      }
      foreach ($cs->allResources($prop) as $val) {
        $label = $val->label($this->lang);
        if ($label) {
          $ret[$prop][] = $label->getValue();
        } else {
          $exvocab = $this->model->guessVocabularyFromURI($val->getURI());
          $exlabel = $this->getExternalLabel($exvocab, $val->getURI(), $this->lang);
          $ret[$prop][] = isset($exlabel) ? $exlabel : $val->getURI();
        }
      }
    }
    if (isset($ret['owl:versionInfo'])) { // if version info availible for vocabulary convert it to a more readable format
      $ret['owl:versionInfo'][0] = $this->parseVersionInfo($ret['owl:versionInfo'][0]);
    }
    // remove duplicate values
    foreach (array_keys($ret) as $prop)
      $ret[$prop] = array_unique($ret[$prop]);
    $ret = $this->arbitrarySort($ret);

    // filtering multiple labels
    if (isset($ret['dc:title']))
      unset($ret['dc11:title'],$ret['skos:prefLabel'],$ret['rdfs:label']);
    else if(isset($ret['dc11:title']))
      unset($ret['skos:prefLabel'],$ret['rdfs:label']);
    else if(isset($ret['skos:prefLabel']))
      unset($ret['rdfs:label']);

    return $ret;
  }

  /**
   * Return all concept schemes in the vocabulary.
   * @return array Array with concept scheme URIs (string) as keys and labels (string) as values
   */

  public function getConceptSchemes()
  {
    return $this->getSparql()->queryConceptSchemes($this->lang);
  }

  /**
   * Return the URI of the default concept scheme of this vocabulary. If the skosmos:mainConceptScheme property is set in the
   * vocabulary configuration, that will be returned. Otherwise an arbitrary concept scheme will be returned.
   * @return string concept scheme URI
   */

  public function getDefaultConceptScheme()
  {
    $conceptScheme = $this->resource->get("skosmos:mainConceptScheme");
    if ($conceptScheme) return $conceptScheme->getUri();

    // mainConceptScheme not explicitly set, guess it
    foreach ($this->getConceptSchemes() as $uri => $csdata) {
      $conceptScheme = $uri; // actually pick the last one
    }

    return $conceptScheme;
  }

  /**
   * Return the top concepts of a concept scheme in the vocabulary.
   * @param string $conceptScheme URI of concept scheme whose top concepts to return. If not set,
   *                              the default concept scheme of the vocabulary will be used.
   * @return array Array with concept URIs (string) as keys and labels (string) as values
   */

  public function getTopConcepts($conceptScheme=null)
  {
    if (!$conceptScheme)
      $conceptScheme = $this->getDefaultConceptScheme();

    return $this->getSparql()->queryTopConcepts($conceptScheme, $this->lang);
  }

  /**
   * Tries to parse version, date and time from sparql version information into a readable format.
   * @param string $version
   * @return string
   */
  private function parseVersionInfo($version)
  {
    $parts = explode(' ', $version);
    if ($parts[0] != '$Id:') return $version; // don't know how to parse
    $rev = $parts[2];
    $datestr = $parts[3] . ' ' . $parts[4];

    return "$datestr (r$rev)";
  }

  /**
   * Counts the statistics of the vocabulary.
   * @return array of the concept counts in different languages
   */
  public function getStatistics()
  {
    $sparql = $this->getSparql();
    $ret = array();
    // find the number of concepts
    $ret['concepts'] = $sparql->countConcepts();
    // count the number of different types of concepts in all languages
    $ret['terms'] = $sparql->countLangConcepts($this->getLanguages());

    return $ret;
  }

  /**
   * get the URL from which the vocabulary data can be downloaded
   */
  public function getDataURL()
  {
    $val = $this->resource->getResource("void:dataDump");
    if ($val)
      return $val->getURI();
    return false;
  }

  /**
   * Returns the class URI used for concept groups in this vocabulary,
   * or null if not set.
   * @return string group class URI or null
   */

  public function getGroupClassURI()
  {
    $val = $this->resource->getResource("skosmos:groupClass");
    if ($val)
      return $val->getURI();
    return null;
  }

  /**
   * Returns the class URI used for thesaurus arrays in this vocabulary,
   * or null if not set.
   * @return string array class URI or null
   */

  public function getArrayClassURI()
  {
    $val = $this->resource->getResource("skosmos:arrayClass");
    if ($val)
      return $val->getURI();
    return null;
  }

  /**
   * Returns a boolean value set in the vocabularies.ttl config.
   * @return boolean
   */
  public function getShowHierarchy()
  {
    $val = $this->resource->getLiteral("skosmos:showTopConcepts");
    if ($val)
      return (boolean) $val->getValue();
    return false;
  }


  /**
   * Gets the parent concepts of a concept and child concepts for all of those.
   * @param string $uri
   */
  public function getConceptHierarchy($uri)
  {
    return $this->getSparql()->queryParentList($uri, $this->lang);
  }

  /**
   * Gets the child relations of a concept and whether these children have more children.
   * @param string $uri
   */
  public function getConceptChildren($uri)
  {
    return $this->getSparql()->queryChildren($uri, $this->lang);
  }

  /**
   * Gets the skos:narrower relations of a concept.
   * @param string $uri
   */
  public function getConceptNarrowers($uri)
  {
    return $this->getSparql()->queryProperty($uri, 'skos:narrower', $this->lang);
  }

  /**
   * Gets the skos:narrowerTransitive relations of a concept.
   * @param string $uri
   * @param integer $limit
   */
  public function getConceptTransitiveNarrowers($uri, $limit)
  {
    return $this->getSparql()->queryTransitiveProperty($uri, 'skos:narrower',$this->lang,$limit);
  }

  /**
   * Gets the skos:broader relations of a concept.
   * @param string $uri
   * @param string $lang language identifier.
   */
  public function getConceptBroaders($uri, $lang='fi')
  {
    return $this->getSparql()->queryProperty($uri, 'skos:broader', $this->lang);
  }

  /**
   * Gets the skos:broaderTransitive relations of a concept.
   * @param string $uri
   * @param integer $limit
   * @param boolean $any set to true if you want to have a label even in case of a correct language one missing.
   */
  public function getConceptTransitiveBroaders($uri, $limit, $any=false)
  {
    return $this->getSparql()->queryTransitiveProperty($uri, 'skos:broader', $this->lang, $limit, $any);
  }

  /**
   * Gets all the skos:related concepts of a concept.
   * @param string $uri
   */
  public function getConceptRelateds($uri)
  {
    return $this->getSparql()->queryProperty($uri,'skos:related', $this->lang);
  }

  /**
   * returns concept's RDF in downloadable format
   * @param string $uri
   * @param string $format is the format in which you want to get the result, currently this function supports
   * text/turtle, application/rdf+xml and application/json
   */
  public function getRDF($uri, $format)
  {
    $sparql = $this->getSparql();

    if ($format == 'text/turtle') {
      $retform = 'turtle';
      $serialiser = new EasyRdf_Serialiser_Turtle();
    } elseif ($format == 'application/ld+json' || $format == 'application/json') {
      $retform = 'jsonld'; // serve JSON-LD for both JSON-LD and plain JSON requests
      $serialiser = new EasyRdf_Serialiser_JsonLd();
    } else {
      $retform = 'rdfxml';
      $serialiser = new EasyRdf_Serialiser_RdfXml();
    }

    $result = $sparql->queryConceptInfo($uri, $this->getArrayClassURI(), null, null, true);

    return $serialiser->serialise($result, $retform);
  }

  /**
   * Makes a query into the sparql endpoint for a concept.
   * @param string $uri the full URI of the concept
   * @return array
   */
  public function getConceptInfo($uri)
  {
    $sparql = $this->getSparql();

    return $sparql->queryConceptInfo($uri, $this->getArrayClassURI(), $this->lang, $this->getId());
  }

  /**
   * Lists the different concept groups available in the vocabulary.
   * @return array
   */
  public function listConceptGroups()
  {
    $ret = array();
    $gclass = $this->getGroupClassURI();
    if ($gclass === null) return $ret; // no group class defined, so empty result
    $groups = $this->getSparql()->listConceptGroups($gclass, $this->lang);
    foreach ($groups as $uri => $label) {
      $ret[$uri] = $label;
    }

    return $ret;
  }

  /**
  * Lists the concepts available in the concept group.
  * @param $clname
  * @return array
  */
  public function listConceptGroupContents($glname)
  {
    $ret = array();
    $gclass = $this->getGroupClassURI();
    if ($gclass === null) return $ret; // no group class defined, so empty result
    $group = $this->getConceptURI($glname);
    $contents = $this->getSparql()->listConceptGroupContents($gclass, $group, $this->lang);
    foreach ($contents as $uri => $label) {
      $ret[$uri] = $label;
    }

    return $ret;
  }
  
  /**
   * Returns the letters of the alphabet which have been used in this vocabulary.
   * The returned letters may also include specials such as '0-9' (digits) and '!*' (special characters).
   * @return array array of letters
   */
  public function getAlphabet() {
    $chars = $this->getSparql()->queryFirstCharacters($this->lang);
    $letters = array();
    $digits = false;
    $specials = false;
    foreach($chars as $char) {
      if (preg_match('/\p{L}/u', $char)) {
        $letters[] = $char;
      } elseif (preg_match('/\d/u', $char)) {
        $digits = true;
      } else {
        $specials = true;
      }
    }
    usort($letters, 'strcoll');
    if ($specials)
      $letters[] = '!*';
    if ($digits)
      $letters[] = '0-9';
    return $letters;
  }
  
  /**
   * Searches for concepts with a label starting with the specified letter.
   * Also the special tokens '0-9' (digits), '!*' (special characters) and '*'
   * (everything) are supported.
   * @param $letter letter (or special token) to search for
   */
  public function searchConceptsAlphabetical($letter) {
    return $this->getSparql()->queryConceptsAlphabetical($letter, $this->lang);
  }

}
