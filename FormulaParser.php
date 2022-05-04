<?php

require_once(APP . "utils/datatype/Strings.php");
require_once(APP . "utils/lexer/Parser.php");
require_once("event/FormulaParserEvent.php");
require_once("FormulaParserCode.php");
require_once("FormulaParserReplaceType.php");
require_once("FormulaParserResult.php");
require_once("FormulaParserValidator.php");
require_once("FormulaParserValidatorType.php");
require_once("FormulaLexer.php");

final class FormulaParser extends Parser{
	private $_iResultCode = FormulaParserCode::NONE;
	private $_sFunctionError = "";
	
	/*
	 * Class functions
	 */
	public function __construct($oLexer){
		if (!$this->initialized()){
			
			/* [Required] Execute the extended class constructor */
			parent::__construct($oLexer);
			
			/* Configure the current object */
			$this->addDisposeListener(function($oSender, $oSenderData, $oEvent){
				unset($oEvent->target->_iResultCode);
				unset($oEvent->target->_sFunctionError);
			}, $this);
			
			/* Configure the lexer */
			$this->lexer()->whiteSpacesOnString(true);
		}
	}
	
	/*
	 * Add a validator listener
	 */
	public function addValidatorListener($oFunction, $oTarget = null, $oData = null){
		return ($this->available() && isset($oFunction) && is_callable($oFunction)) ? $this->addListener("validator", $oFunction, new FormulaParserEvent("validator", $oTarget, $oData)) : false;
	}
	
	/*
	 * Validate syntax and parse the formula
	 */
	public function parse(){
		$iMatch = 0;
		$this->_iResultCode = FormulaParserCode::NONE;
		$this->startCapture(true);
		if ($this->available()){
			
			/* Formula delimiter */
			if ($this->match(TokenType()->LEFT_DELIMITER)){
				$iMatch++;
				
				/* Checks the function name */
				if ($this->match(TokenType()->STRING)){
					
					/* Is function */
					if ($this->_function(true)){
						$iMatch++;
						
						/* Formula delimiter */
						if ($this->match(TokenType()->RIGHT_DELIMITER)){
							
							/* Checks if the last token is null */
							if ($this->token() == null){
								$iMatch++;
							} else {
								$this->_iResultCode = FormulaParserCode::INVALID_FORMULA;
							}
						} else {
							$this->_iResultCode = FormulaParserCode::MISSING_RIGHT_DELIMITER;
						}
					}
				} else {
					$this->_iResultCode = FormulaParserCode::MISSING_FUNCTION_NAME;
				}
			} else {
				$this->_iResultCode = FormulaParserCode::MISSING_LEFT_DELIMITER;
			}
			
			/* Get the result of the parser */
			$sParser = "";
			$sProcessed = $this->stopCapture(true, true);
			$iCode = FormulaParserCode::NONE;
			$sErrorText = "";
			
			/* Valid formula */
			if ($iMatch == 3){
				$sParser = ($this->parser() != "") ? substr($this->parser(), 1, strlen($this->parser()) - 2) : "";
				$sProcessed = "";
				$iCode = FormulaParserCode::SUCCESS;
			} else {
				switch($iCode = $this->_iResultCode){
					case FormulaParserCode::INVALID_NUMBER_OF_PARAMETERS:
						$sErrorText = $this->_sFunctionError;
						break;
						
					case FormulaParserCode::INVALID_FORMULA:
						$sErrorText = $this->token()->text;
						break;
						
					default:
						$sErrorText = ($this->matchToken() != null) ? $this->matchToken()->text : "";
						
						/* Fix processed */
						if (($sErrorText != "") && ($sProcessed != "") && !Strings::endWith($sProcessed, $sErrorText)){
							if ($iPositions = strrpos($sProcessed, $sErrorText)){
								$sProcessed = substr($sProcessed, 0, $iPositions + strlen($sErrorText));
							}
							unset($iPositions);
						}
						break;
				}
			}
			
			/* Send the result */
			return new FormulaParserResult($sParser, $sProcessed, $iCode, $sErrorText);
		} else {
			
			/* Stop capture */
			$this->stopCapture(true, true);
			
			/* Send the result */
			return new FormulaParserResult("", "", FormulaParserCode::PARSER_UNAVAILABLE, "Parser Unavailable");
		}
	}
	
	/*
	 * Checks if the next token is a valid variable
	 */
	private function _variable($bMatchToken = false){
		$iNoMatch = 0;
		if ($this->available()){
			
			/* Start capturing the variable */
			$this->startCapture(false, $bMatchToken);
			
			/* Variable delimiter */
			if ((!$bMatchToken && $this->match(TokenType()->LEFT_BRACKET)) || ($bMatchToken && ($this->matchToken() != null) && ($this->matchToken()->type == TokenType()->LEFT_BRACKET))){
				
				/* Variable symbol */
				if ($this->match(TokenType()->VARIABLE_SYMBOL)){
					
					/* Variable name */
					if ($this->match(TokenType()->STRING) && $this->_isVariableName($this->matchToken()->text)){
						
						/* Variable delimiter */
						if (!$this->match(TokenType()->RIGHT_BRACKET)){
							$iNoMatch++;
							$this->_iResultCode = FormulaParserCode::MISSING_RIGHT_BRACKET;
						}
					} else {
						$iNoMatch++;
						$this->_iResultCode = FormulaParserCode::INVALID_VARIABLE_NAME;
					}
				} else {
					$iNoMatch++;
					$this->_iResultCode = FormulaParserCode::MISSING_OR_INVALID_VARIABLE_SYMBOL;
				}
			} else {
				$iNoMatch++;
				$this->_iResultCode = FormulaParserCode::MISSING_LEFT_BRACKET;
			}
			
			/* Stop capturing the variable */
			if ($iNoMatch == 0){
				
				/* Trigger parser listener */
				$this->stopCapture(false, false, true, FormulaParserReplaceType::VARIABLE);
			}
		} else {
			$iNoMatch++;
		}
		return ($iNoMatch == 0);
	}
	
	/*
	 * Checks if the next token is a valid string
	 */
	private function _string($bMatchToken = false){
		$iMatch = 0;
		if ($this->available()){
			
			/* Start capturing the string */
			$this->startCapture(false, $bMatchToken);
			
			/* String delimiter */
			if ((!$bMatchToken && $this->match(TokenType()->QUOTE)) || ($bMatchToken && ($this->matchToken() != null) && ($this->matchToken()->type == TokenType()->QUOTE))){
				
				$sQuote = $this->matchToken()->text;
				$iMatch++;
				
				while(true){
					
					/* String delimiter */
					if ($this->match(TokenType()->QUOTE) && ($sQuote == $this->matchToken()->text)){
						$iMatch++;
						
						/* Trigger parser listener */
						$this->stopCapture(false, false, true, FormulaParserReplaceType::STRING);
						break;
						
					/* Get the next token */
					} else {
						/* Force to get the next token */
						$this->match($this->token()->type);
					}
					
					/* Close the validations */
					if ($iMatch >= 2) break;
					
					/* Security for the loop */
					if (!$this->available()) break;
					if ($this->token() == null) break;
				}
				
				/* Invalid string */
				if ($iMatch < 2){
					$this->_iResultCode = FormulaParserCode::MISSING_QUOTE;
				}
				
				/* Free Memory */
				unset($sQuote);
			} else {
				$this->_iResultCode = FormulaParserCode::MISSING_QUOTE;
			}
		}
		return ($iMatch == 2);
	}
	
	/*
	 * Checks if the next token is a valid function
	 */
	private function _function($bMatchToken = false){
		$iNoMatch = 0;
		if ($this->available()){
			
			/* Start capturing the function name */
			$this->startCapture(false, $bMatchToken);
			
			/* Function name */
			if (((!$bMatchToken && $this->match(TokenType()->STRING)) || ($bMatchToken && ($this->matchToken() != null) && ($this->matchToken()->type == TokenType()->STRING))) 
				&& $this->_existsFunction($this->matchToken()->text)){
				
				/* Function delimiter */
				if ($this->match(TokenType()->LEFT_PARENTHESIS)){
					
					/* Trigger parser listener */
					$sFunctionName = $this->stopCapture(false, false, true, FormulaParserReplaceType::FUNCTION_NAME);
					
					/* Check parameters */
					if ($this->_parameters(substr($sFunctionName, 0, strlen($sFunctionName) - 1))){
						
						/* Function delimiter */
						if (!$this->match(TokenType()->RIGHT_PARENTHESIS)){
							$iNoMatch++;
							$this->_iResultCode = FormulaParserCode::MISSING_RIGHT_PARENTHESIS;
						}
					} else {
						$iNoMatch++;
					}
					
					/* Free Memory */
					unset($sFunctionName);
				} else {
					$iNoMatch++;
					$this->_iResultCode = FormulaParserCode::MISSING_LEFT_PARENTHESIS;
				}
			} else {
				$iNoMatch++;
				$this->_iResultCode = FormulaParserCode::FUNCTION_UNAVAILABLE;
			}
		} else {
			$iNoMatch++;
		}
		return ($iNoMatch == 0);
	}
	
	/*
	 * Checks if the next token is a valid parameter
	 */
	private function _parameters($sFunctionName){
		$iNoMatch = 0;
		if ($this->available() && isset($sFunctionName) && ($sFunctionName != "")){
			$iParameters = 0;
			while(true){
				
				/* Is function, variable or string */
				if ($this->match(array(
					TokenType()->STRING,
					TokenType()->LEFT_BRACKET,
					TokenType()->QUOTE
				))){
					switch($this->matchToken()->type){
						case TokenType()->STRING:
							if (!$this->_function(true)){
								$iNoMatch++;
							} else {
								$iParameters++;
							}
							break;
							
						case TokenType()->LEFT_BRACKET:
							if (!$this->_variable(true)){
								$iNoMatch++;
							} else {
								$iParameters++;
							}
							break;
							
						case TokenType()->QUOTE:
							if (!$this->_string(true)){
								$iNoMatch++;
							} else {
								$iParameters++;
							}
							break;
					}
					
					/* Check if exists another parameter */
					if ($iNoMatch == 0){
						if (!$this->match(TokenType()->COMMA)){
							
							/* Check if the number of parameters of the functions is correct */
							if (!$this->_isValidNumberParametersFunction($sFunctionName, $iParameters)){
								$iNoMatch++;
								$this->_iResultCode = FormulaParserCode::INVALID_NUMBER_OF_PARAMETERS;
								$this->_sFunctionError = $sFunctionName;
							}
							break;
						}
					}
				} else {
					$iNoMatch++;
					$this->_iResultCode = FormulaParserCode::INVALID_PARAMETER;
				}
				
				/* Close the validations */
				if ($iNoMatch > 0) break;
				
				/* Security for the loop */
				if (!$this->available()) break;
				if ($this->token() == null) break;
			}
			
			/* Free Memory */
			unset($iParameters);
		} else {
			$iNoMatch++;
		}
		return ($iNoMatch == 0);
	}
	
	/*
	 * Checks if the function name exists
	 */
	private function _existsFunction($sName){
		$bExists = false;
		if ($this->available() && isset($sName) && is_string($sName)){
			$oValidator = new FormulaParserValidator();
			$oValidator->text = $sName;
			$oValidator->type = FormulaParserValidatorType::EXISTS_FUNCTION;
			$this->triggerListener("validator", null, $this, $oValidator);
			$bExists = (property_exists($oValidator, "valid") && is_bool($oValidator->valid) && $oValidator->valid);
			unset($oValidator);
		}
		return $bExists;
	}
	
	/*
	 * Checks the number of parameters of the function
	 */
	private function _isValidNumberParametersFunction($sName, $iParameters){
		$bValid = false;
		if ($this->available() && isset($sName) && isset($iParameters) && is_string($sName) && is_numeric($iParameters)){
			$oValidator = new FormulaParserValidator();
			$oValidator->text = $sName;
			$oValidator->type = FormulaParserValidatorType::NUMBER_OF_PARAMETERS;
			$oValidator->data = $iParameters;
			$this->triggerListener("validator", null, $this, $oValidator);
			$bValid = (property_exists($oValidator, "valid") && is_bool($oValidator->valid) && $oValidator->valid);
			unset($oValidator);
		}
		return $bValid;
	}
	
	/*
	 * Checks if the variable name is valid
	 */
	private function _isVariableName($sName){
		$bValid = false;
		if ($this->available() && isset($sName) && is_string($sName)){
			$oValidator = new FormulaParserValidator();
			$oValidator->text = $sName;
			$oValidator->type = FormulaParserValidatorType::IS_VARIABLE_NAME;
			$this->triggerListener("validator", null, $this, $oValidator);
			$bValid = (property_exists($oValidator, "valid") && is_bool($oValidator->valid) && $oValidator->valid);
			unset($oValidator);
		}
		return $bValid;
	}
}