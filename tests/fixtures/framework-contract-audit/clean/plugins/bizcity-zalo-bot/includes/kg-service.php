<?php

// Compliant fixture: external package uses the KG-Hub service owner.
BizCity_KG_Notebook_Service::instance()->list_for_user( get_current_user_id() );