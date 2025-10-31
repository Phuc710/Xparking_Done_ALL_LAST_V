import asyncio
import websockets
import json
import logging
from typing import Set, Dict, Any
from fastapi import FastAPI, WebSocket, WebSocketDisconnect
from fastapi.staticfiles import StaticFiles
import uvicorn
from db_api import DatabaseAPI_instance as db_api
from config import get_vn_time

logger = logging.getLogger('XParking.WebSocket')

class WebSocketManager:
    def __init__(self):
        self.active_connections: Set[WebSocket] = set()
        self.connection_info: Dict[WebSocket, Dict[str, Any]] = {}
    
    async def connect(self, websocket: WebSocket, client_info: Dict[str, Any] = None):
        """Accept WebSocket connection"""
        await websocket.accept()
        self.active_connections.add(websocket)
        self.connection_info[websocket] = client_info or {}
        logger.info(f"🔌 WebSocket client connected. Total: {len(self.active_connections)}")
    
    def disconnect(self, websocket: WebSocket):
        """Remove WebSocket connection"""
        self.active_connections.discard(websocket)
        self.connection_info.pop(websocket, None)
        logger.info(f"🔌 WebSocket client disconnected. Total: {len(self.active_connections)}")
    
    async def send_personal_message(self, message: Dict[str, Any], websocket: WebSocket):
        """Send message to specific client"""
        try:
            await websocket.send_text(json.dumps(message))
        except Exception as e:
            logger.error(f"Error sending personal message: {e}")
            self.disconnect(websocket)
    
    async def broadcast(self, message: Dict[str, Any], event_type: str = None):
        """Broadcast message to all connected clients"""
        if not self.active_connections:
            return
        
        message_data = {
            "type": event_type or "broadcast",
            "data": message,
            "timestamp": get_vn_time()
        }
        
        disconnected = set()
        for connection in self.active_connections:
            try:
                await connection.send_text(json.dumps(message_data))
            except Exception as e:
                logger.error(f"Error broadcasting to client: {e}")
                disconnected.add(connection)
        
        # Remove disconnected clients
        for connection in disconnected:
            self.disconnect(connection)
        
        logger.info(f"📡 Broadcasted {event_type} to {len(self.active_connections)} clients")

# Global WebSocket manager
manager = WebSocketManager()

# FastAPI app for WebSocket server
app = FastAPI(title="XParking Realtime API")

@app.websocket("/ws")
async def websocket_endpoint(websocket: WebSocket):
    """WebSocket endpoint for realtime updates"""
    await manager.connect(websocket)
    try:
        while True:
            # Keep connection alive and handle incoming messages
            data = await websocket.receive_text()
            try:
                message = json.loads(data)
                await handle_websocket_message(websocket, message)
            except json.JSONDecodeError:
                await manager.send_personal_message({
                    "type": "error",
                    "message": "Invalid JSON format"
                }, websocket)
    except WebSocketDisconnect:
        manager.disconnect(websocket)
    except Exception as e:
        logger.error(f"WebSocket error: {e}")
        manager.disconnect(websocket)

async def handle_websocket_message(websocket: WebSocket, message: Dict[str, Any]):
    """Handle incoming WebSocket messages from clients"""
    try:
        msg_type = message.get("type")
        
        if msg_type == "ping":
            await manager.send_personal_message({
                "type": "pong",
                "timestamp": get_vn_time()
            }, websocket)
        
        elif msg_type == "subscribe":
            # Handle subscription to specific events
            events = message.get("events", [])
            manager.connection_info[websocket]["subscribed_events"] = events
            await manager.send_personal_message({
                "type": "subscription_confirmed",
                "events": events
            }, websocket)
        
        elif msg_type == "get_slots":
            # Send current slot status
            slots = db_api.get_available_slots()
            await manager.send_personal_message({
                "type": "slots_status",
                "data": slots
            }, websocket)
        
    except Exception as e:
        logger.error(f"Error handling WebSocket message: {e}")
        await manager.send_personal_message({
            "type": "error",
            "message": str(e)
        }, websocket)

# API endpoints for external systems to push updates
@app.post("/api/broadcast/slot_update")
async def broadcast_slot_update(data: Dict[str, Any]):
    """API endpoint to broadcast slot updates"""
    await manager.broadcast(data, "slot_update")
    return {"status": "broadcasted", "clients": len(manager.active_connections)}

@app.post("/api/broadcast/payment_update")
async def broadcast_payment_update(data: Dict[str, Any]):
    """API endpoint to broadcast payment updates"""
    await manager.broadcast(data, "payment_update")
    return {"status": "broadcasted", "clients": len(manager.active_connections)}

@app.post("/api/broadcast/vehicle_update")
async def broadcast_vehicle_update(data: Dict[str, Any]):
    """API endpoint to broadcast vehicle updates"""
    await manager.broadcast(data, "vehicle_update")
    return {"status": "broadcasted", "clients": len(manager.active_connections)}

@app.get("/api/status")
async def get_status():
    """Get WebSocket server status"""
    return {
        "status": "running",
        "connected_clients": len(manager.active_connections),
        "timestamp": get_vn_time()
    }

# Integration with Database realtime
def setup_database_integration():
    """Setup integration với Database realtime"""
    
    # Override emit function để broadcast qua WebSocket
    original_emit = db_api._emit_to_web_clients
    
    async def emit_to_websockets(event_type: str, data: Dict[str, Any]):
        """Enhanced emit function broadcasts qua WebSocket"""
        await manager.broadcast(data, event_type)
        # Also call original function for logging
        original_emit(event_type, data)
    
    # Replace emit function
    db_api._emit_to_web_clients = emit_to_websockets

def run_websocket_server(host: str = "localhost", port: int = 8080):
    """Run the WebSocket server"""
    logger.info(f"🚀 Starting WebSocket server on {host}:{port}")
    setup_database_integration()
    uvicorn.run(app, host=host, port=port, log_level="info")

if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO)
    run_websocket_server()
