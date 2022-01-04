<?php

	class Dimmer extends IPSModule
	{

		public function Create() {
			//Never delete this line!
			parent::Create();
			
			$this->RegisterPropertyString("lampData", "");
			$this->RegisterPropertyFloat("maxShortPressTime", 0.4);
			$this->RegisterPropertyInteger("pollingInterval", 5);
			$this->RegisterPropertyInteger("maxPollingDuration", 30);
			$this->RegisterPropertyInteger("TriggerInputObject", 0);
			$this->RegisterVariableBoolean("DMX_DimUp", "DimUp");
		}
		
		public function ApplyChanges(){
			//Never delete this line!
			parent::ApplyChanges();

			//This function is called every time you hit te apply button in the GUI and when the object is created

			$TriggerInputObject = $this->ReadPropertyInteger("TriggerInputObject");

			$dimmerObject = IPS_GetObject($this->InstanceID);
			$eventExists = false;
			foreach($dimmerObject['ChildrenIDs'] as $childObject){
				$objectInfo = IPS_GetObject($childObject);
				//objectType 4 = event
				if($objectInfo['ObjectType'] == 4){
					$eventInfo = IPS_GetEvent($objectInfo['ObjectID']);

					if($eventInfo['TriggerVariableID'] == $TriggerInputObject){
						//event word getriggerd op oude variable id, verwijder deze
						$eventExists = true;
					}
				}
			}

			if($TriggerInputObject <> 0 && !$eventExists){
				//create event
				$eid = IPS_CreateEvent(0);        //triggered event
				IPS_SetEventTrigger($eid, 4, $TriggerInputObject); //On change of variable with ID 15 754
				IPS_SetEventTriggerValue ($eid, true);
				IPS_SetEventAction($eid, "{28E92DFA-1640-2F3B-74F6-4B2AAE21CE22}", ["FUNCTION" => 'Dim_ButtonPress', "VARIABLE" => 0, "SAVE_RETURN_VALUE" => FALSE]);
				IPS_SetParent($eid, $this->InstanceID); //Assigning the event
				IPS_SetEventActive($eid, true);    //Activate the event
			}
			
		}

		public function UpdateTriggerInput(int $TriggerInputObject){
			$TriggerInputObjectOld = $this->ReadPropertyInteger("TriggerInputObject");
			
			if($TriggerInputObjectOld <> $TriggerInputObject){
				//search for old trigger with action on Id: TriggerInputObjectOld
				$dimmerObject = IPS_GetObject($this->InstanceID);
			
				//loop door childobjects heen opzoek naar een trigger
				foreach($dimmerObject['ChildrenIDs'] as $childObject){
					$objectInfo = IPS_GetObject($childObject);
					//objectType 4 = event
					if($objectInfo['ObjectType'] == 4){
						$eventInfo = IPS_GetEvent($objectInfo['ObjectID']);
	
						if($eventInfo['TriggerVariableID'] == $TriggerInputObjectOld){
							//event word getriggerd op oude variable id, verwijder deze
							IPS_DeleteEvent($objectInfo['ObjectID']);
						}
					}
				}
			}
		}

		public function GetConfigurationForm(){
			$data = json_decode(file_get_contents(__DIR__ . "/form.json"));
			if($this->ReadPropertyString("lampData") == "") {			
				$data->elements[0]->values[] = Array(
					"lampId" => 12435,
					"state" => "OK!",
					"MinDimValue" => 20,
					"rowColor" => "#ff0000"
				);
			} 
			else {
				//Annotate existing elements
				$lampData = json_decode($this->ReadPropertyString("lampData"));
				foreach($lampData as $lamp) {
					//We only need to add annotations. Remaining data is merged from persistance automatically.
					//Order is determinted by the order of array elements
					if(IPS_ObjectExists($lamp->LampId)) {
						$data->elements[0]->values[] = Array(
							"name" => IPS_GetName($lamp->LampId),
							"state" => "OK!"
						);
					} else {
						$data->elements[0]->values[] = Array(
							"name" => "Not found!",
							"state" => "FAIL!",
							"rowColor" => "#ff0000"
						);
					}								
				}
			}
			
			return json_encode($data);
		
		}	

		public function buttonPress(){

			//Variabale van het forumlier ophalen
			$maxShortPressTime = $this->ReadPropertyFloat("maxShortPressTime");
			$pollingInterval = $this->ReadPropertyInteger("pollingInterval");
			$maxPollingDuration = $this->ReadPropertyInteger("maxPollingDuration");
			$TriggerInputObject = $this->ReadPropertyInteger("TriggerInputObject");

			//lamp informatie ophalen
			$lampData = json_decode($this->ReadPropertyString("lampData"));

			$currentStatus = getValueInteger($lampData[0]->LampId);

			//bij het dimmen heb ik een dimMinPercentage nodig 
			$DMX_MinPercentage = 0;
			foreach($lampData as $lamp){
				//we kunnen niet lager dimmen dan het laagste percentage is de tabel
				$DMX_MinPercentage = ($lamp->MinDimValue > $DMX_MinPercentage ? $lamp->MinDimValue : $DMX_MinPercentage);
			}

			//Als we geen min dim percentage kunnen vinden of deze is te hoog dan pakken we de default dim value van 20%
			$DMX_MinPercentage = (($DMX_MinPercentage <= 0 || $DMX_MinPercentage >= 100) ? 20 : $DMX_MinPercentage);

			if (isset($_IPS['VARIABLE'])){

				$startTime = microtime(true);
				$duration = 0;

				//invert dim up value each button press
				$this->SetValue("DMX_DimUp", !($this->GetValue("DMX_DimUp")));

				while ($duration < $maxPollingDuration && GetValue(IPS_GetEvent ($_IPS['PARENT'])['TriggerVariableID']) == true){
					
					IPS_Sleep($pollingInterval);
					$duration = microtime(true) - $startTime;

					if($duration >= $maxShortPressTime){
						//in while loop, long press dus we gaan dimmen
						if($this->GetValue("DMX_DimUp")){
							//bool true = up
							 if($currentStatus < 255){
								 $currentStatus++;
							 }
							 else{
								 //waarde word te hoog, we gaan de andere kant weer op dimmen
								$this->SetValue("DMX_DimUp", !($this->GetValue("DMX_DimUp")));
								//slaap een halve seconde
								usleep(500000);
							 }
						}
						else{
							//bool false = down
							if($currentStatus > 255/100*$DMX_MinPercentage){
								$currentStatus--;
							}
							else{
								//waarde word te laag, we gaan de andere kant weer op dimmen
								$this->SetValue("DMX_DimUp", !($this->GetValue("DMX_DimUp")));
								//slaap een halve seconde
								usleep(500000);
							}
						}
			
						//tijd om de lamp in te stellen
						foreach($lampData as $lamp){
							$lampObject = IPS_GetObject($lamp->LampId);
							DMX_SetChannel($lampObject['ParentID'],(str_replace('ChannelValue', '',$lampObject['ObjectIdent'])),$currentStatus);
						}
					}

				}

				if($duration <= $maxShortPressTime){
					//Short press

					//controlleer of 1 van de lampen aan staat, als dit zo is dan moeten deze uit
       				//als geen van de lampen aan staat dan zetten we deze aan
					$lampenAan = false;
					foreach($lampData as $lamp){
						if(GetValueInteger($lamp->LampId) >= round(255/100*$lamp->MinDimValue,0)){
							//print("Lamp met id ".$lamp->LampId." brand \n");
							$lampenAan = true;
							break;
						}
						else{
							//print("Lamp met id ".$lamp->LampId." brand niet \n");
						}
					}

					if($lampenAan){
						//er brand een lamp dus zet alles uit
						$this->TurnLightsOff();
					}
					else{
						//er brand geen lamp dus zet alles aan
						$this->TurnLightsOn();
					}

				}
				else{
					//long press buiten de loop
				}
			}
			else{
				//functie kan alleen aangeroepene worden door een trigger
			}
		}


		public function TurnLightsOff(){
			$lampData = json_decode($this->ReadPropertyString("lampData"));
			
			foreach($lampData as $lamp){
				$lampObject = IPS_GetObject($lamp->LampId);
				
				$lampLastValueSet = false;
				foreach($lampObject['ChildrenIDs'] as $lampChild){
					$childObject = IPS_GetObject($lampChild);
					if($childObject['ObjectName'] == "LastValue"){
						//Lamp heeft child object met name LastValue
						SetValueInteger($lampChild, GetValueInteger($lamp->LampId));
						$lampLastValueSet = true;
						break;
					}
				}
				if(!$lampLastValueSet){
					//Lamp heeft geen child object met name LastValue, dus maak deze aan
					$lastValueVar  = IPS_CreateVariable (1); // 1= integer type
					IPS_SetName ($lastValueVar, "LastValue"); 
					IPS_SetVariableCustomProfile ($lastValueVar, "~Intensity.255");
					IPS_SetParent ($lastValueVar,  $lamp->LampId );
					IPS_SetHidden($lastValueVar, true); 
					SetValueInteger($lastValueVar, GetValueInteger($lamp->LampId));
				}   
				//zet de lamp pas uit nadat de lastValue is opgeslagen anders word het niks 
				DMX_FadeChannel($lampObject['ParentID'],(str_replace('ChannelValue', '',$lampObject['ObjectIdent'])),0,1);
			}
		}

		public function TurnLightsOn(){
			$lampData = json_decode($this->ReadPropertyString("lampData"));

			foreach($lampData as $lamp){
				$lampObject = IPS_GetObject($lamp->LampId);

				//value as integer, dus max is 255
				$lastDimValue = 0;
				//zoek in het child object van de lamp naar een lastValue van de dimwaarde
				foreach($lampObject['ChildrenIDs'] as $lampChild){
					$childObject = IPS_GetObject($lampChild);
					if($childObject['ObjectName'] == "LastValue"){
						$lastDimValue = getValueInteger($childObject['ObjectID']);
						break;
					}
				}
				if($lastDimValue > round(255/100*$lamp->MinDimValue,0)){
					//lastDimValue gevonden en deze is groter dan de min dim waarde
					DMX_FadeChannel($lampObject['ParentID'],(str_replace('ChannelValue', '',$lampObject['ObjectIdent'])),$lastDimValue,1);
				}
				else{
					//geen lastDimValue gevonden of deze is niet groot genoeg, gebruik min dim waarde 
					DMX_FadeChannel($lampObject['ParentID'],(str_replace('ChannelValue', '',$lampObject['ObjectIdent'])),round(255/100*$lamp->MinDimValue,0),1);
				}
			}
		}
	}