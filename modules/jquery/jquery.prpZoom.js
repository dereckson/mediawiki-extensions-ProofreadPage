/*
 * jQuery PrpZoom Plugin
 * Image zoom library
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

( function( $ ) {
	'use strict';

	$.widget( 'mw.prpZoom', {

		options: {
			zoomStep: 6,
			moveStep: 6,
			animationDuration: 10
		},

		default: {
			width: 0,
			height: 0
		},

		zoomStep: {
			width: 0,
			height: 0
		},

		moveStep: {
			width: 0,
			height: 0
		},

		_getPosition: function() {
			var position = this.element.position();
			position.width = this.element.width();
			position.height = this.element.height();
			return position;
		},

		_applyPosition: function( position ) {
			this.element.animate( {
				top: Math.round( position.top ),
				left: Math.round( position.left ),
				width: Math.round( position.width ),
				height: Math.round( position.height )
			}, this.options.animationDuration );
		},

		_create: function() {
			//config params
			this.default = {
				width: this.element.width(),
				height: this.element.height()
			};
			this.zoomStep = {
				width: this.options.zoomStep * this.default.width / 100,
				height: this.options.zoomStep * this.default.height / 100
			};
			this.moveStep = {
				width: this.options.moveStep * this.default.width / 100,
				height: this.options.moveStep * this.default.height / 100
			};

			//setup area
			this.element.parent().css( {
				position: 'relative',
				overflow: 'auto',
				height: this.default.height,
				cursor: 'move'
			} );

			this.element.css( {
				position: 'absolute'
			} ).draggable( {
				scroll: true
			} );

			this.reset();

			var element = this.element;
			$.each( this._events, function( event, handler ) {
				element.bind( event, handler );
			} );
		},

		_events: {
			//depends on jquery.mousewheel.js
			'mousewheel': function( event ) {
				if( event.deltaY > 0 ) {
					$( this ).prpZoom( 'zoomOut' );
				} else if( event.deltaY < 0 ) {
					$( this ).prpZoom( 'zoomIn' );
				}
				event.preventDefault(); //Don't scroll while zooming
			}
		},

		reset: function() {
			this._applyPosition( {
				top: 0,
				left: 0,
				width: this.default.width,
				height: this.default.height
			} );
		},

		zoomIn: function() {
			this._zoom( 1 );
		},

		zoomOut: function() {
			this._zoom( -1 );
		},

		_zoom: function( proportion ) {
			var position = this._getPosition();
			position.left -= proportion * this.zoomStep.width;
			position.top -=  proportion * this.zoomStep.height;
			position.width += 2 *  proportion * this.zoomStep.width;
			position.height += 2 *  proportion * this.zoomStep.height;
			this._applyPosition( position );
		},

		moveLeft: function () {
			var position = this._getPosition();
			position.left -= this.moveStep.width;
			this._applyPosition( position );
		},

		moveRight: function () {
			var position = this._getPosition();
			position.left += this.moveStep.width;
			this._applyPosition( position );
		},

		moveUp: function () {
			var position = this._getPosition();
			position.top -= this.moveStep.height;
			this._applyPosition( position );
		},

		moveDown: function () {
			var position = this._getPosition();
			position.top += this.moveStep.height;
			this._applyPosition( position );
		},

		destroy: function() {
			this.element
				.draggable( 'destroy' )
				.css( {
					position: 'static',
					top: '',
					left: '',
					width: '',
					height: ''
				} )
				.parent().css( {
					position: 'static',
					overflow: '',
					height: '',
					cursor: ''
				} );

			var element = this.element;
			$.each( this._events, function( event, handler ) {
				element.unbind( event, handler );
			} );

			$.Widget.prototype.destroy.call( this );
		}
	} );

} ) ( jQuery );