<?php

	class BUGSpriority extends BUGSdatatype 
	{

		protected static $_items = null;

		/**
		 * Returns all priorities available
		 * 
		 * @return array 
		 */		
		public static function getAll()
		{
			if (self::$_items === NULL)
			{
				self::$_items = array();
				if ($items = B2DB::getTable('B2tListTypes')->getAllByItemType(self::PRIORITY))
				{
					foreach ($items as $row_id => $row)
					{
						self::$_items[$row_id] = BUGSfactory::BUGSpriorityLab($row_id, $row);
					}
				}
			}
			return self::$_items;
		}

		/**
		 * Delete a priority id
		 *
		 * @param integer $id
		 */
		public static function delete($id)
		{
			B2DB::getTable('B2tListTypes')->deleteByTypeAndId(self::PRIORITY, $id);
		}

		/**
		 * Constructor
		 * 
		 * @param integer $item_id The item id
		 * @param B2DBrow $row [optional] A B2DBrow to use
		 * @return 
		 */
		public function __construct($item_id, $row = null)
		{
			try
			{
				$this->initialize($item_id, self::PRIORITY, $row);
			}
			catch (Exception $e)
			{
				throw $e;
			}
		}
		
	}

?>