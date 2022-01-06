<?php

	class HVCOphaaldatums extends IPSModule
	{

		public function Create() {
			//Never delete this line!
			parent::Create();
			
			$this->RegisterPropertyString("bagid", "");
			$this->RegisterPropertyString("bagDescription", "");
			$this->RegisterPropertyString("Postcode", 0);
			$this->RegisterPropertyString("huisnr", 0);
			$this->RegisterPropertyBoolean("pushMelding", true);
			$this->RegisterPropertyInteger("WebFrontId", 0);
			
		}
		
		public function ApplyChanges(){
			//Never delete this line!
			parent::ApplyChanges();

			//This function is called every time you hit te apply button in the GUI and when the object is created
			$this->updateCyclic('DailyRun');
		}

		public function GetConfigurationForm(){
			$data = json_decode(file_get_contents(__DIR__ . "/form.json"));
			$data->elements[5]->visible = $this->ReadPropertyBoolean("pushMelding");
			return json_encode($data);
		}

		public function getBagId(){
			$json = file_get_contents("https://inzamelkalender.hvcgroep.nl/adressen/".$this->ReadPropertyString("Postcode").":".$this->ReadPropertyString("huisnr"));
			$adressen = json_decode($json);
			
			$this->UpdateFormField("bagid", "value", $adressen[0]->bagid);
			$this->UpdateFormField("bagDescription", "value", $adressen[0]->description);
		}

		public function getAfvalstromen(){
			$json = file_get_contents("https://inzamelkalender.hvcgroep.nl/rest/adressen/".$this->ReadPropertyString("bagid")."/afvalstromen");
			$afvalstromenRaw = json_decode($json);
			$afvalstromen = array();

			foreach($afvalstromenRaw as $afvalstroom){
				$d = DateTime::createFromFormat('Y-m-d', $afvalstroom->ophaaldatum);
				//controlleer of het child object een ophaaldatum heeft
				if($d && $d->format('Y-m-d') === $afvalstroom->ophaaldatum){
					//voeg object toe aan de afvalstroom array met id en title 
					$afvalstromen[$afvalstroom->id]=($afvalstroom->title);
				}
			}

			$ophaaldata = file_get_contents("https://inzamelkalender.hvcgroep.nl/rest/adressen/".$this->ReadPropertyString("bagid")."/ophaaldata");
			$datums = json_decode($ophaaldata);

			foreach($datums as $datum){
				$childObjectId = @IPS_GetObjectIDByName($afvalstromen[$datum->afvalstroom_id], $this->InstanceID);

				if($childObjectId > 0 && IPS_ObjectExists($childObjectId)){
					//Object met juiste naam gevonden, werk datum bij 
					SetValueInteger($childObjectId, strtotime($datum->ophaaldatum));
				}
				else{
					//object bestaat niet, maak deze aan
					$id = $this->RegisterVariableInteger($datum->afvalstroom_id, $afvalstromen[$datum->afvalstroom_id], '~UnixTimestampDate');
					SetValueInteger($id, strtotime($datum->ophaaldatum));
				}

				$today = new DateTime("today");
				$diff = $today->diff(DateTime::createFromFormat('Y-m-d', $datum->ophaaldatum));
				$diffDays = (integer) $diff->format("%R%a");


				if($diffDays == 1){
					//Morgen word er wat opgehaald
					WFC_PushNotification ( $this->ReadPropertyInteger('WebFrontId') ,  'HVC Afval' ,  "Let op! Morgen word ".$afvalstromen[$datum->afvalstroom_id]." opgehaald." ,  'happy', 0) ;
					// echo ("Let op! Morgen word ".$afvalstromen[$datum->afvalstroom_id]." opgehaald. Melding verstuurd naar: ".$this->ReadPropertyInteger('WebFrontId')." \n");
				}


				IPS_SetPosition((isset($id) ? $id : $childObjectId), $diffDays);
			}
		}

		public function toggleShowFormField(string $Field, bool $visible) {
            $this->UpdateFormField($Field, 'visible', $visible);
        }


		protected function updateCyclic(string $ident) {
			$id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
		
			if ($id && IPS_GetEvent($id)['EventType'] <> 1) {
			  IPS_DeleteEvent($id);
			  $id = 0;
			}
		
			if (!$id) {
			  $id = IPS_CreateEvent(1);
			  IPS_SetParent($id, $this->InstanceID);
			  IPS_SetIdent($id, $ident);
			}
		
			IPS_SetName($id, $ident);
			IPS_SetHidden($id, true);
			
			IPS_SetEventAction($id, "{28E92DFA-1640-2F3B-74F6-4B2AAE21CE22}", ["FUNCTION" => 'hvc_getAfvalstromen', "VARIABLE" => 0, "SAVE_RETURN_VALUE" => FALSE]);
		
			if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");
		
			IPS_SetEventCyclic ($id, 2, 1, 0, 0, 0, 0);
			IPS_SetEventCyclicTimeFrom($id, 19,0,0);

			//set event active
			IPS_SetEventActive($id, true);             

		  }

	}